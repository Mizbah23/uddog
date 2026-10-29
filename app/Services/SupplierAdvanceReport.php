<?php

namespace App\Services;

use App\Models\SupplierAdvance;
use App\Models\SupplierAdvanceDelivery;
use App\Models\SupplierTtEntry;

class SupplierAdvanceReport
{
    private const PAID_ENTRY_TYPES = ['opening_paid', 'incentive', 'commission', 'credit'];

    public function build(int $organizationId, int $branchId, ?string $from, ?string $to, ?string $companySearch = null): array
    {
        $matchesCompany = fn (string $company) => ! $companySearch
            || str_contains(mb_strtolower($company), mb_strtolower($companySearch));

        $advances = SupplierAdvance::query()
            ->where('organization_id', $organizationId)
            ->where('branch_id', $branchId)
            ->get()
            ->filter(fn (SupplierAdvance $advance) => $matchesCompany($advance->company_name));
        $deliveries = SupplierAdvanceDelivery::query()
            ->where('organization_id', $organizationId)
            ->where('branch_id', $branchId)
            ->with('advance:id,company_name,reference')
            ->get()
            ->filter(fn (SupplierAdvanceDelivery $delivery) => $matchesCompany($delivery->company_name ?: $delivery->advance?->company_name ?: ''));
        $entries = SupplierTtEntry::query()
            ->where('organization_id', $organizationId)
            ->where('branch_id', $branchId)
            ->get()
            ->filter(fn (SupplierTtEntry $entry) => $matchesCompany($entry->company_name));

        $events = [];
        foreach ($advances as $advance) {
            $events[] = $this->event(
                $advance->advance_date->toDateString(), $advance->id, 1, $advance->company_name,
                'Advance sent', $advance->reference ?: 'ADV-'.$advance->id, '', '', (float) $advance->amount, 0,
            );
        }
        foreach ($deliveries as $delivery) {
            $events[] = $this->event(
                $delivery->delivery_date->toDateString(), $delivery->id, 2, $delivery->company_name ?: $delivery->advance?->company_name ?: '',
                'Products received', $delivery->advance?->reference ?: ($delivery->advance ? 'ADV-'.$delivery->advance->id : ''),
                $delivery->installment_code, $delivery->product_details, 0, (float) $delivery->amount,
            );
        }
        foreach ($entries as $entry) {
            $paid = in_array($entry->entry_type, self::PAID_ENTRY_TYPES, true);
            $events[] = $this->event(
                $entry->entry_date->toDateString(), $entry->id, $paid ? 0 : 3, $entry->company_name,
                $entry->particular ?: $this->entryLabel($entry->entry_type), '', '', '',
                $paid ? (float) $entry->amount : 0, $paid ? 0 : (float) $entry->amount, $entry->entry_type,
            );
        }

        usort($events, fn (array $left, array $right) => [$left['date'], $left['order'], $left['id']] <=> [$right['date'], $right['order'], $right['id']]);

        $summary = $this->emptyTotals();
        $companyTotals = [];
        $runningBalance = 0;
        $rows = [];

        foreach ($events as $event) {
            if ($to && $event['date'] > $to) {
                continue;
            }

            $key = mb_strtolower(trim($event['company']));
            $companyTotals[$key] ??= ['company' => trim($event['company']), ...$this->emptyTotals()];

            if ($from && $event['date'] < $from) {
                $this->addToTotals($summary, $event, true);
                $this->addToTotals($companyTotals[$key], $event, true);
                $runningBalance += $event['advance'] - $event['received'];

                continue;
            }

            $this->addToTotals($summary, $event);
            $this->addToTotals($companyTotals[$key], $event);
            $runningBalance += $event['advance'] - $event['received'];
            $rows[] = [
                'date' => $event['date'],
                'company' => $event['company'],
                'particular' => $event['particular'],
                'reference' => $event['reference'],
                'installment_code' => $event['installment_code'],
                'product_details' => $event['product_details'],
                'advance' => round($event['advance'], 2),
                'received' => round($event['received'], 2),
                'balance' => round($runningBalance, 2),
            ];
        }

        foreach ($companyTotals as &$total) {
            $this->finalizeTotals($total);
        }
        unset($total);
        $this->finalizeTotals($summary);

        $companyTotals = array_values($companyTotals);
        usort($companyTotals, fn (array $left, array $right) => strnatcasecmp($left['company'], $right['company']));

        return [
            'columns' => ['Date', 'Company', 'Particular', 'Reference', 'Installment code', 'Product details', 'Advance / credits', 'Products received', 'Balance'],
            'rows' => $rows,
            'company_totals' => $companyTotals,
            'summary' => $summary,
            'note' => 'This TT register is independent of suppliers, purchase invoices, stock, payments, cash balances and the general ledger. Enter values here separately.',
        ];
    }

    private function event(string $date, int $id, int $order, string $company, string $particular, string $reference, string $installmentCode, string $productDetails, float $advance, float $received, ?string $entryType = null): array
    {
        return compact('date', 'id', 'order', 'company', 'particular', 'reference', 'installmentCode', 'productDetails', 'advance', 'received', 'entryType') + [
            'installment_code' => $installmentCode,
            'product_details' => $productDetails,
        ];
    }

    private function emptyTotals(): array
    {
        return [
            'opening_paid' => 0.0,
            'opening_received' => 0.0,
            'advance_sent' => 0.0,
            'other_credits' => 0.0,
            'products_received' => 0.0,
            'other_received' => 0.0,
        ];
    }

    private function addToTotals(array &$totals, array $event, bool $beforeRange = false): void
    {
        if ($beforeRange) {
            $totals['opening_paid'] += $event['advance'];
            $totals['opening_received'] += $event['received'];

            return;
        }

        if ($event['entryType'] === 'opening_paid') {
            $totals['opening_paid'] += $event['advance'];
        } elseif ($event['entryType'] === 'opening_received') {
            $totals['opening_received'] += $event['received'];
        } elseif (in_array($event['entryType'], ['incentive', 'commission', 'credit'], true)) {
            $totals['other_credits'] += $event['advance'];
        } elseif ($event['entryType'] === 'received_adjustment') {
            $totals['other_received'] += $event['received'];
        } elseif ($event['advance'] > 0) {
            $totals['advance_sent'] += $event['advance'];
        } else {
            $totals['products_received'] += $event['received'];
        }
    }

    private function finalizeTotals(array &$totals): void
    {
        foreach (array_keys($this->emptyTotals()) as $key) {
            $totals[$key] = round($totals[$key], 2);
        }
        $totals['total_paid'] = round($totals['opening_paid'] + $totals['advance_sent'] + $totals['other_credits'], 2);
        $totals['total_received'] = round($totals['opening_received'] + $totals['products_received'] + $totals['other_received'], 2);
        $totals['opening_balance'] = round($totals['opening_paid'] - $totals['opening_received'], 2);
        $totals['closing_balance'] = round($totals['total_paid'] - $totals['total_received'], 2);
        $totals['advance_balance'] = max($totals['closing_balance'], 0);
        $totals['due_balance'] = max(-$totals['closing_balance'], 0);
    }

    private function entryLabel(string $entryType): string
    {
        return match ($entryType) {
            'opening_paid' => 'Opening advance paid',
            'opening_received' => 'Opening products received',
            'incentive' => 'Incentive',
            'commission' => 'Commission',
            'credit' => 'Other credit',
            'received_adjustment' => 'Other products received',
        };
    }
}
