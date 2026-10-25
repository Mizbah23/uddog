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
        $add = function (string $date, string $reference, string $type, ?string $fromName, ?string $toName, int $cashIn, int $cashOut, int $transfer, ?string $recordedAt, ?string $notes, int $salesChange = 0) use (&$events): void {
            $events[] = compact('date', 'reference', 'type', 'fromName', 'toName', 'cashIn', 'cashOut', 'transfer', 'recordedAt', 'notes', 'salesChange');
        };

        Document::query()->where('organization_id', $organizationId)->where('branch_id', $branchId)
            ->whereIn('type', ['sale', 'resale', 'sale_return'])
            ->when($dateTo, fn ($query) => $query->whereDate('document_date', '<=', $dateTo))
            ->with('creator:id,name')->get()->each(function (Document $document) use ($add): void {
                $return = $document->type === 'sale_return';
                $cashRefund = $return && $document->payment_method === 'cash';
                $add($document->document_date->toDateString(), $document->number, $return ? 'Sale return' : 'Sale recorded',
                    $return ? $document->creator?->name : null, $return ? null : $document->creator?->name,
                    0, $cashRefund ? self::cents($document->total) : 0, 0,
                    $document->created_at?->toDateTimeString(), $document->notes,
                    ($return ? -1 : 1) * self::cents($document->total));
            });

        SalePayment::query()->where('organization_id', $organizationId)->where('branch_id', $branchId)->where('payment_method', 'cash')
            ->when($dateTo, fn ($query) => $query->whereDate('payment_date', '<=', $dateTo))
            ->with('document:id,number', 'receiver:id,name')->get()->each(function (SalePayment $payment) use ($add): void {
                $add($payment->payment_date->toDateString(), $payment->document?->number ?? 'SAL-'.$payment->document_id, 'Sale collection', null, $payment->receiver?->name, self::cents($payment->amount), 0, 0, $payment->created_at?->toDateTimeString(), $payment->notes);
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
        $salesOpening = 0;
        $balance = 0;
        $salesBalance = 0;
        $totals = ['sales_recorded' => 0, 'sales_returns' => 0, 'cash_collected' => 0, 'supplier_refunds' => 0, 'supplier_payments' => 0, 'cash_expenses' => 0, 'customer_refunds' => 0, 'staff_transfers' => 0, 'owner_withdrawals' => 0];
        $rows = [];
        foreach ($events as $event) {
            $change = $event['cashIn'] - $event['cashOut'];
            $balance += $change;
            $salesChange = $event['salesChange'] - ($event['type'] === 'Owner withdrawal' ? $event['cashOut'] : 0);
            $salesBalance += $salesChange;
            if ($dateFrom && $event['date'] < $dateFrom) {
                $opening += $change;
                $salesOpening += $salesChange;

                continue;
            }
            $key = match ($event['type']) {
                'Sale recorded' => 'sales_recorded',
                'Sale return' => 'sales_returns',
                'Sale collection' => 'cash_collected',
                'Supplier refund' => 'supplier_refunds',
                'Supplier payment' => 'supplier_payments',
                'Cash expense' => 'cash_expenses',
                'Customer refund' => 'customer_refunds',
                'Staff transfer' => 'staff_transfers',
                default => 'owner_withdrawals',
            };
            $totals[$key] += $event['salesChange'] ? abs($event['salesChange']) : ($event['transfer'] ?: ($event['cashIn'] ?: $event['cashOut']));
            if ($event['type'] === 'Sale return' && $event['cashOut']) {
                $totals['customer_refunds'] += $event['cashOut'];
            }
            $rows[] = [
                'date' => $event['date'], 'recorded_at' => $event['recordedAt'] ?? '—',
                'reference' => $event['reference'], 'movement' => $event['type'], 'notes' => $event['notes'] ?: '—',
                'from' => $event['fromName'] ?? '—', 'to' => $event['toName'] ?? '—',
                'cash_in' => $event['cashIn'] / 100, 'cash_out' => $event['cashOut'] / 100,
                'transfer' => $event['transfer'] / 100, 'store_balance' => $balance / 100,
                'sales_change' => $salesChange / 100, 'sales_balance' => $salesBalance / 100,
            ];
        }

        return [
            'columns' => ['Date', 'Recorded at', 'Reference', 'Movement', 'Notes', 'From', 'To', 'Cash in', 'Cash out', 'Transfer', 'Physical cash balance', 'Sales change', 'Withdrawable sales balance'],
            'rows' => $rows,
            'summary' => ['opening_balance' => $opening / 100, 'opening_sales_balance' => $salesOpening / 100, ...array_map(fn (int $amount) => $amount / 100, $totals), 'closing_balance' => $balance / 100, 'closing_sales_balance' => $salesBalance / 100],
            'note' => 'Withdrawable sales balance follows full recorded sales less sale returns and owner withdrawals, regardless of payment method or collection status. Staff transfers only move that balance between users. Physical cash balance separately follows recorded cash receipts and payments and may differ from the amount eligible for withdrawal.',
        ];
    }

    private static function cents(string|int|float $amount): int
    {
        return (int) round((float) $amount * 100);
    }
}
