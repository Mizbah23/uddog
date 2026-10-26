<?php

namespace App\Services;

use App\Models\CashMovement;
use App\Models\Document;
use App\Models\Expense;
use App\Models\PurchasePayment;
use App\Models\SalePayment;

class CashMovementReport
{
    /** @return array<string, mixed> */
    public function build(int $organizationId, int $branchId, ?string $dateFrom, ?string $dateTo): array
    {
        $events = [];
        $add = function (string $date, string $reference, string $type, ?string $fromName, ?string $toName, int $cashIn, int $cashOut, int $transfer, ?string $recordedAt, ?string $notes, int $paidChange = 0) use (&$events): void {
            $events[] = compact('date', 'reference', 'type', 'fromName', 'toName', 'cashIn', 'cashOut', 'transfer', 'recordedAt', 'notes', 'paidChange');
        };

        Document::query()->where('organization_id', $organizationId)->where('branch_id', $branchId)
            ->where('type', 'sale_return')
            ->whereIn('payment_method', ['cash', 'card', 'mobile_banking', 'bank_transfer'])
            ->when($dateTo, fn ($query) => $query->whereDate('document_date', '<=', $dateTo))
            ->with('creator:id,name')->get()->each(function (Document $document) use ($add): void {
                $add($document->document_date->toDateString(), $document->number, 'Customer refund',
                    $document->creator?->name, null, 0,
                    $document->payment_method === 'cash' ? self::cents($document->total) : 0, 0,
                    $document->created_at?->toDateTimeString(), $document->notes, -self::cents($document->total));
            });

        SalePayment::query()->where('organization_id', $organizationId)->where('branch_id', $branchId)
            ->whereIn('payment_method', ['cash', 'card', 'mobile_banking', 'bank_transfer'])
            ->when($dateTo, fn ($query) => $query->whereDate('payment_date', '<=', $dateTo))
            ->with('document:id,number', 'receiver:id,name')->get()->each(function (SalePayment $payment) use ($add): void {
                $amount = self::cents($payment->amount);
                $add($payment->payment_date->toDateString(), $payment->document?->number ?? 'SAL-'.$payment->document_id, 'Customer payment', null, $payment->receiver?->name,
                    $payment->payment_method === 'cash' ? $amount : 0, 0, 0,
                    $payment->created_at?->toDateTimeString(), $payment->notes ?: ucfirst(str_replace('_', ' ', $payment->payment_method)), $amount);
            });
        Document::query()->where('organization_id', $organizationId)->where('branch_id', $branchId)
            ->whereIn('type', ['sale', 'resale'])->where('amount_paid', '>', 0)
            ->whereDoesntHave('payments')
            ->when($dateTo, fn ($query) => $query->whereDate('document_date', '<=', $dateTo))
            ->with('creator:id,name')->get()->each(function (Document $document) use ($add): void {
                $paid = max(0, self::cents($document->amount_paid) - self::cents($document->gold_exchange_value ?? 0));
                if ($paid > 0) {
                    $add($document->document_date->toDateString(), $document->number, 'Legacy customer payment', null,
                        $document->creator?->name, 0, 0, 0, $document->created_at?->toDateTimeString(),
                        'Paid amount on invoice without a payment record', $paid);
                }
            });
        PurchasePayment::query()->where('organization_id', $organizationId)->where('branch_id', $branchId)->where('payment_method', 'cash')
            ->when($dateTo, fn ($query) => $query->whereDate('payment_date', '<=', $dateTo))
            ->with('document:id,number', 'receiver:id,name')->get()->each(function (PurchasePayment $payment) use ($add): void {
                $add($payment->payment_date->toDateString(), $payment->document?->number ?? 'PUR-'.$payment->document_id, 'Supplier payment', $payment->receiver?->name, null, 0, self::cents($payment->amount), 0, $payment->created_at?->toDateTimeString(), $payment->notes);
            });
        Document::query()->where('organization_id', $organizationId)->where('branch_id', $branchId)
            ->where('type', 'purchase_return')->where('payment_method', 'cash')
            ->when($dateTo, fn ($query) => $query->whereDate('document_date', '<=', $dateTo))
            ->with('creator:id,name')->get()->each(function (Document $document) use ($add): void {
                $incoming = $document->type === 'purchase_return';
                $add($document->document_date->toDateString(), $document->number, $incoming ? 'Supplier refund' : 'Customer refund', $incoming ? null : $document->creator?->name, $incoming ? $document->creator?->name : null, $incoming ? self::cents($document->total) : 0, $incoming ? 0 : self::cents($document->total), 0, $document->created_at?->toDateTimeString(), $document->notes);
            });
        Expense::query()->where('organization_id', $organizationId)->where('branch_id', $branchId)->where('payment_method', 'cash')
            ->when($dateTo, fn ($query) => $query->whereDate('expense_date', '<=', $dateTo))
            ->with('creator:id,name')->get()->each(function (Expense $expense) use ($add): void {
                $add($expense->expense_date->toDateString(), 'EXP-'.$expense->id, 'Cash expense', $expense->creator?->name, null, 0, self::cents($expense->amount), 0, $expense->created_at?->toDateTimeString(), $expense->notes);
            });
        CashMovement::query()->where('organization_id', $organizationId)->where('branch_id', $branchId)
            ->when($dateTo, fn ($query) => $query->whereDate('created_at', '<=', $dateTo))
            ->with('sender:id,name', 'recipient:id,name')->get()->each(function (CashMovement $movement) use ($add): void {
                $isTransfer = $movement->type === 'transfer';
                $amount = self::cents($movement->amount);
                $add($movement->created_at->toDateString(), 'CASH-'.$movement->id, $isTransfer ? 'Staff transfer' : 'Owner withdrawal', $movement->sender?->name, $movement->recipient?->name, 0, $isTransfer ? 0 : $amount, $isTransfer ? $amount : 0, $movement->created_at->toDateTimeString(), $movement->notes);
            });

        usort($events, fn (array $left, array $right): int => [$left['date'], $left['recordedAt'] ?? '', $left['reference'], $left['type']]
            <=> [$right['date'], $right['recordedAt'] ?? '', $right['reference'], $right['type']]);

        $opening = 0;
        $paidOpening = 0;
        $balance = 0;
        $paidBalance = 0;
        $totals = ['customer_payments' => 0, 'cash_collected' => 0, 'supplier_refunds' => 0, 'supplier_payments' => 0, 'cash_expenses' => 0, 'customer_refunds' => 0, 'staff_transfers' => 0, 'owner_withdrawals' => 0];
        $rows = [];
        foreach ($events as $event) {
            $change = $event['cashIn'] - $event['cashOut'];
            $balance += $change;
            $paidChange = $event['paidChange'] - ($event['type'] === 'Owner withdrawal' ? $event['cashOut'] : 0);
            $paidBalance += $paidChange;
            if ($dateFrom && $event['date'] < $dateFrom) {
                $opening += $change;
                $paidOpening += $paidChange;

                continue;
            }
            $key = match ($event['type']) {
                'Customer payment' => 'customer_payments',
                'Legacy customer payment' => 'customer_payments',
                'Supplier refund' => 'supplier_refunds',
                'Supplier payment' => 'supplier_payments',
                'Cash expense' => 'cash_expenses',
                'Customer refund' => 'customer_refunds',
                'Staff transfer' => 'staff_transfers',
                default => 'owner_withdrawals',
            };
            $totals[$key] += $event['paidChange'] ? abs($event['paidChange']) : ($event['transfer'] ?: ($event['cashIn'] ?: $event['cashOut']));
            if ($event['type'] === 'Customer payment') {
                $totals['cash_collected'] += $event['cashIn'];
            }
            $rows[] = [
                'date' => $event['date'], 'recorded_at' => $event['recordedAt'] ?? '—',
                'reference' => $event['reference'], 'movement' => $event['type'], 'notes' => $event['notes'] ?: '—',
                'from' => $event['fromName'] ?? '—', 'to' => $event['toName'] ?? '—',
                'cash_in' => $event['cashIn'] / 100, 'cash_out' => $event['cashOut'] / 100,
                'transfer' => $event['transfer'] / 100, 'store_balance' => $balance / 100,
                'paid_change' => $paidChange / 100, 'paid_balance' => $paidBalance / 100,
            ];
        }

        return [
            'columns' => ['Date', 'Recorded at', 'Reference', 'Movement', 'Notes', 'From', 'To', 'Cash in', 'Cash out', 'Transfer', 'Physical cash balance', 'Paid change', 'Withdrawable paid balance'],
            'rows' => $rows,
            'summary' => ['opening_balance' => $opening / 100, 'opening_paid_balance' => $paidOpening / 100, ...array_map(fn (int $amount) => $amount / 100, $totals), 'closing_balance' => $balance / 100, 'closing_paid_balance' => $paidBalance / 100],
            'note' => 'Withdrawable balance includes recorded customer payments by cash, card, mobile banking and bank transfer, less refunded returns and owner withdrawals. Unpaid sales and gold exchanges are excluded. Staff transfers move the balance between users without changing the store total. Physical cash is shown separately.',
        ];
    }

    private static function cents(string|int|float $amount): int
    {
        return (int) round((float) $amount * 100);
    }
}
