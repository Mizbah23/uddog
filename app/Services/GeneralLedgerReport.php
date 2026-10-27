<?php

namespace App\Services;

use App\Models\CashMovement;
use App\Models\Document;
use App\Models\Expense;

class GeneralLedgerReport
{
    /** @return array<string, string> */
    public static function accounts(): array
    {
        return [
            'cash' => '1000 · Cash',
            'bank' => '1010 · Bank and digital payments',
            'unclassified' => '1090 · Unclassified payments',
            'receivables' => '1100 · Accounts receivable',
            'inventory' => '1200 · Inventory',
            'payables' => '2000 · Accounts payable',
            'owner_drawings' => '3000 · Owner drawings',
            'sales' => '4000 · Sales revenue',
            'sales_returns' => '4010 · Sales returns',
            'cost_of_goods' => '5000 · Cost of goods sold',
            'expenses' => '6000 · Operating expenses',
        ];
    }

    /** @return array<string, mixed> */
    public function build(int $organizationId, int $branchId, ?string $dateFrom, ?string $dateTo, ?string $account): array
    {
        $entries = [];
        $sequence = 0;
        $post = function (string $date, string $reference, string $details, string $accountKey, int $debit, int $credit, ?string $party = null, ?string $sourceType = null, ?int $sourceId = null, ?array $sourceRecord = null) use (&$entries, &$sequence): void {
            if ($debit === 0 && $credit === 0) {
                return;
            }
            $entries[] = [
                'date' => $date, 'account_key' => $accountKey, 'reference' => $reference,
                'details' => $details, 'debit_cents' => $debit, 'credit_cents' => $credit,
                'party' => $party, 'source_type' => $sourceType, 'source_id' => $sourceId,
                'source_record' => $sourceRecord,
                'sequence' => $sequence++,
            ];
        };

        Document::query()->where('organization_id', $organizationId)->where('branch_id', $branchId)
            ->whereIn('type', ['sale', 'resale', 'sale_return', 'purchase', 'purchase_return'])
            ->with(['contact:id,name', 'items:id,document_id,quantity,cost_price', 'payments:id,document_id,amount,payment_method,payment_date', 'purchasePayments:id,document_id,amount,payment_method,payment_date'])
            ->orderBy('id')->chunkById(200, function ($documents) use ($post): void {
                foreach ($documents as $document) {
                    $postDocument = fn (string $date, string $reference, string $details, string $accountKey, int $debit, int $credit) => $post($date, $reference, $details, $accountKey, $debit, $credit, $document->contact?->name, 'document', $document->id);
                    $date = $document->document_date->toDateString();
                    $reference = $document->number;
                    $details = ucfirst(str_replace('_', ' ', $document->type)).' · '.($document->contact?->name ?? 'Unknown contact');
                    $total = self::cents($document->total);
                    $cost = (int) $document->items->sum(fn ($item) => (int) round((float) $item->quantity * self::cents($item->cost_price)));

                    if (in_array($document->type, ['sale', 'resale'], true)) {
                        $postDocument($date, $reference, $details, 'receivables', $total, 0);
                        $postDocument($date, $reference, $details, 'sales', 0, $total);
                        $postDocument($date, $reference, $details, 'cost_of_goods', $cost, 0);
                        $postDocument($date, $reference, $details, 'inventory', 0, $cost);
                    } elseif ($document->type === 'sale_return') {
                        $postDocument($date, $reference, $details, 'sales_returns', $total, 0);
                        $postDocument($date, $reference, $details, 'receivables', 0, $total);
                        $postDocument($date, $reference, $details, 'inventory', $cost, 0);
                        $postDocument($date, $reference, $details, 'cost_of_goods', 0, $cost);
                    } elseif ($document->type === 'purchase') {
                        $postDocument($date, $reference, $details, 'inventory', $total, 0);
                        $postDocument($date, $reference, $details, 'payables', 0, $total);
                    } else {
                        $postDocument($date, $reference, $details, 'payables', $total, 0);
                        $postDocument($date, $reference, $details, 'inventory', 0, $total);
                    }

                    if (in_array($document->type, ['sale_return', 'purchase_return'], true)
                        && in_array($document->payment_method, ['cash', 'card', 'mobile_banking', 'bank_transfer'], true)) {
                        $moneyAccount = self::moneyAccount($document->payment_method);
                        if ($document->type === 'sale_return') {
                            $postDocument($date, $reference, 'Customer refund settled', 'receivables', $total, 0);
                            $postDocument($date, $reference, 'Customer refund settled', $moneyAccount, 0, $total);
                        } else {
                            $postDocument($date, $reference, 'Supplier refund received', $moneyAccount, $total, 0);
                            $postDocument($date, $reference, 'Supplier refund received', 'payables', 0, $total);
                        }
                    }

                    $payments = $document->type === 'purchase' ? $document->purchasePayments : $document->payments;
                    foreach ($payments as $payment) {
                        $amount = self::cents($payment->amount);
                        $paymentDate = $payment->payment_date->toDateString();
                        $paymentDetails = 'Payment · '.($document->contact?->name ?? 'Unknown contact');
                        $moneyAccount = $payment->payment_method === 'gold_exchange' ? 'inventory' : self::moneyAccount($payment->payment_method);
                        if ($document->type === 'purchase') {
                            $postDocument($paymentDate, $reference, $paymentDetails, 'payables', $amount, 0);
                            $postDocument($paymentDate, $reference, $paymentDetails, $moneyAccount, 0, $amount);
                        } else {
                            $postDocument($paymentDate, $reference, $paymentDetails, $moneyAccount, $amount, 0);
                            $postDocument($paymentDate, $reference, $paymentDetails, 'receivables', 0, $amount);
                        }
                    }

                    // Older imported invoices may have a paid amount without individual payment records.
                    if (in_array($document->type, ['sale', 'resale', 'purchase'], true)) {
                        $unrecorded = max(0, self::cents($document->amount_paid) - (int) $payments->sum(fn ($payment) => self::cents($payment->amount)));
                        if ($unrecorded > 0) {
                            $moneyAccount = self::moneyAccount($document->payment_method);
                            if ($document->type === 'purchase') {
                                $postDocument($date, $reference, 'Payment recorded on invoice', 'payables', $unrecorded, 0);
                                $postDocument($date, $reference, 'Payment recorded on invoice', $moneyAccount, 0, $unrecorded);
                            } else {
                                $postDocument($date, $reference, 'Payment recorded on invoice', $moneyAccount, $unrecorded, 0);
                                $postDocument($date, $reference, 'Payment recorded on invoice', 'receivables', 0, $unrecorded);
                            }
                        }
                    }
                }
            });

        Expense::query()->where('organization_id', $organizationId)->where('branch_id', $branchId)
            ->when($dateTo, fn ($query) => $query->whereDate('expense_date', '<=', $dateTo))
            ->orderBy('id')->chunkById(200, function ($expenses) use ($post): void {
                foreach ($expenses as $expense) {
                    $date = $expense->expense_date->toDateString();
                    $amount = self::cents($expense->amount);
                    $details = $expense->category.' · '.$expense->description;
                    $sourceRecord = $expense->only(['category', 'description', 'amount', 'expense_date', 'payment_method', 'notes']);
                    $post($date, 'EXP-'.$expense->id, $details, 'expenses', $amount, 0, null, 'expense', $expense->id, $sourceRecord);
                    $post($date, 'EXP-'.$expense->id, $details, self::moneyAccount($expense->payment_method), 0, $amount, null, 'expense', $expense->id, $sourceRecord);
                }
            });

        CashMovement::query()->where('organization_id', $organizationId)->where('branch_id', $branchId)
            ->when($dateTo, fn ($query) => $query->whereDate('created_at', '<=', $dateTo))
            ->with('sender:id,name', 'recipient:id,name')
            ->orderBy('id')->chunkById(200, function ($movements) use ($post): void {
                foreach ($movements as $movement) {
                    $date = $movement->created_at->toDateString();
                    $reference = 'CASH-'.$movement->id;
                    $amount = self::cents($movement->amount);
                    $record = [
                        'type' => $movement->type, 'amount' => $movement->amount,
                        'from' => $movement->sender?->name, 'to' => $movement->recipient?->name,
                        'notes' => $movement->notes, 'created_at' => $movement->created_at,
                    ];
                    if ($movement->type === 'transfer') {
                        $post($date, $reference, 'Cash handed over by '.$movement->sender?->name, 'cash', 0, $amount, $movement->sender?->name, 'cash_movement', $movement->id, $record);
                        $post($date, $reference, 'Cash received by '.$movement->recipient?->name, 'cash', $amount, 0, $movement->recipient?->name, 'cash_movement', $movement->id, $record);
                    } else {
                        $post($date, $reference, 'Owner cash withdrawal', 'owner_drawings', $amount, 0, $movement->sender?->name, 'cash_movement', $movement->id, $record);
                        $post($date, $reference, 'Owner cash withdrawal', 'cash', 0, $amount, $movement->sender?->name, 'cash_movement', $movement->id, $record);
                    }
                }
            });

        $accounts = self::accounts();
        $accountOrder = array_flip(array_keys($accounts));
        usort($entries, fn (array $left, array $right): int => [$accountOrder[$left['account_key']], $left['date'], $left['sequence']]
            <=> [$accountOrder[$right['account_key']], $right['date'], $right['sequence']]);

        $balances = array_fill_keys(array_keys($accounts), 0);
        $opening = 0;
        $totalDebit = 0;
        $totalCredit = 0;
        $rows = collect();
        foreach ($entries as $entry) {
            if ($dateFrom && $entry['date'] < $dateFrom) {
                $balances[$entry['account_key']] += $entry['debit_cents'] - $entry['credit_cents'];

                continue;
            }
            if ($dateTo && $entry['date'] > $dateTo) {
                continue;
            }
            if ($account && $entry['account_key'] !== $account) {
                continue;
            }
            if ($account && $rows->isEmpty()) {
                $opening = $balances[$account];
            }
            $balances[$entry['account_key']] += $entry['debit_cents'] - $entry['credit_cents'];
            $totalDebit += $entry['debit_cents'];
            $totalCredit += $entry['credit_cents'];
            $rows->push([
                'date' => $entry['date'], 'customer_supplier' => $entry['party'] ?? '—',
                'reference' => $entry['reference'], 'details' => $entry['details'],
                'debit' => $entry['debit_cents'] / 100, 'credit' => $entry['credit_cents'] / 100,
                'balance' => $balances[$entry['account_key']] / 100,
                'invoice' => $entry['reference'], 'account' => $accounts[$entry['account_key']],
                'source_type' => $entry['source_type'], 'source_id' => $entry['source_id'],
                'source_record' => $entry['source_record'],
            ]);
        }

        if ($account && $rows->isEmpty()) {
            $opening = $balances[$account];
        }
        $summary = [
            'entries' => $rows->count(),
            'total_debit' => $totalDebit / 100,
            'total_credit' => $totalCredit / 100,
        ];
        if ($account) {
            $summary['opening_balance'] = $opening / 100;
            $summary['closing_balance'] = $balances[$account] / 100;
        }

        return [
            'columns' => ['Date', 'Customer/Supplier', 'Reference', 'Details', 'Debit', 'Credit', 'Balance (Dr+/Cr−)', 'Action/Invoice'],
            'rows' => $rows,
            'summary' => $summary,
            'accounts' => $accounts,
            'note' => 'Derived from recorded sales, purchases, returns, payments, expenses and cash movements. Staff cash handovers have equal debit and credit postings in Cash, so they do not change store cash or sales. Owner withdrawals reduce Cash and increase Owner drawings. Opening balances, manual stock adjustments and historical returns without a recorded settlement are not included. Unknown payment methods appear under Unclassified payments. A negative balance is a credit balance.',
        ];
    }

    private static function cents(string|int|float $amount): int
    {
        return (int) round((float) $amount * 100);
    }

    private static function moneyAccount(?string $method): string
    {
        return match ($method) {
            'cash' => 'cash',
            'card', 'mobile_banking', 'bank_transfer' => 'bank',
            default => 'unclassified',
        };
    }
}
