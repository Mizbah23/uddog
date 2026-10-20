<?php

namespace App\Services;

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
        $post = function (string $date, string $reference, string $details, string $accountKey, int $debit, int $credit) use (&$entries, &$sequence): void {
            if ($debit === 0 && $credit === 0) {
                return;
            }
            $entries[] = [
                'date' => $date, 'account_key' => $accountKey, 'reference' => $reference,
                'details' => $details, 'debit_cents' => $debit, 'credit_cents' => $credit,
                'sequence' => $sequence++,
            ];
        };

        Document::query()->where('organization_id', $organizationId)->where('branch_id', $branchId)
            ->whereIn('type', ['sale', 'resale', 'sale_return', 'purchase', 'purchase_return'])
            ->with(['contact:id,name', 'items:id,document_id,quantity,cost_price', 'payments:id,document_id,amount,payment_method,payment_date', 'purchasePayments:id,document_id,amount,payment_method,payment_date'])
            ->orderBy('id')->chunkById(200, function ($documents) use ($post): void {
                foreach ($documents as $document) {
                    $date = $document->document_date->toDateString();
                    $reference = $document->number;
                    $details = ucfirst(str_replace('_', ' ', $document->type)).' · '.($document->contact?->name ?? 'Unknown contact');
                    $total = self::cents($document->total);
                    $cost = (int) $document->items->sum(fn ($item) => (int) round((float) $item->quantity * self::cents($item->cost_price)));

                    if (in_array($document->type, ['sale', 'resale'], true)) {
                        $post($date, $reference, $details, 'receivables', $total, 0);
                        $post($date, $reference, $details, 'sales', 0, $total);
                        $post($date, $reference, $details, 'cost_of_goods', $cost, 0);
                        $post($date, $reference, $details, 'inventory', 0, $cost);
                    } elseif ($document->type === 'sale_return') {
                        $post($date, $reference, $details, 'sales_returns', $total, 0);
                        $post($date, $reference, $details, 'receivables', 0, $total);
                        $post($date, $reference, $details, 'inventory', $cost, 0);
                        $post($date, $reference, $details, 'cost_of_goods', 0, $cost);
                    } elseif ($document->type === 'purchase') {
                        $post($date, $reference, $details, 'inventory', $total, 0);
                        $post($date, $reference, $details, 'payables', 0, $total);
                    } else {
                        $post($date, $reference, $details, 'payables', $total, 0);
                        $post($date, $reference, $details, 'inventory', 0, $total);
                    }

                    $payments = $document->type === 'purchase' ? $document->purchasePayments : $document->payments;
                    foreach ($payments as $payment) {
                        $amount = self::cents($payment->amount);
                        $paymentDate = $payment->payment_date->toDateString();
                        $paymentDetails = 'Payment · '.($document->contact?->name ?? 'Unknown contact');
                        $moneyAccount = $payment->payment_method === 'gold_exchange' ? 'inventory' : self::moneyAccount($payment->payment_method);
                        if ($document->type === 'purchase') {
                            $post($paymentDate, $reference, $paymentDetails, 'payables', $amount, 0);
                            $post($paymentDate, $reference, $paymentDetails, $moneyAccount, 0, $amount);
                        } else {
                            $post($paymentDate, $reference, $paymentDetails, $moneyAccount, $amount, 0);
                            $post($paymentDate, $reference, $paymentDetails, 'receivables', 0, $amount);
                        }
                    }

                    // Older imported invoices may have a paid amount without individual payment records.
                    if (in_array($document->type, ['sale', 'resale', 'purchase'], true)) {
                        $unrecorded = max(0, self::cents($document->amount_paid) - (int) $payments->sum(fn ($payment) => self::cents($payment->amount)));
                        if ($unrecorded > 0) {
                            $moneyAccount = self::moneyAccount($document->payment_method);
                            if ($document->type === 'purchase') {
                                $post($date, $reference, 'Payment recorded on invoice', 'payables', $unrecorded, 0);
                                $post($date, $reference, 'Payment recorded on invoice', $moneyAccount, 0, $unrecorded);
                            } else {
                                $post($date, $reference, 'Payment recorded on invoice', $moneyAccount, $unrecorded, 0);
                                $post($date, $reference, 'Payment recorded on invoice', 'receivables', 0, $unrecorded);
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
                    $post($date, 'EXP-'.$expense->id, $details, 'expenses', $amount, 0);
                    $post($date, 'EXP-'.$expense->id, $details, self::moneyAccount($expense->payment_method), 0, $amount);
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
                'date' => $entry['date'], 'account' => $accounts[$entry['account_key']],
                'reference' => $entry['reference'], 'details' => $entry['details'],
                'debit' => $entry['debit_cents'] / 100, 'credit' => $entry['credit_cents'] / 100,
                'balance' => $balances[$entry['account_key']] / 100,
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
            'columns' => ['Date', 'Account', 'Reference', 'Details', 'Debit', 'Credit', 'Balance (Dr+/Cr−)'],
            'rows' => $rows,
            'summary' => $summary,
            'accounts' => $accounts,
            'note' => 'Derived from recorded sales, purchases, returns, payments and expenses. Opening balances, manual stock adjustments and unrecorded refunds are not included. Unknown payment methods appear under Unclassified payments. A negative balance is a credit balance.',
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
