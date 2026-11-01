<?php

namespace App\Services;

use App\TenantContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class SupplierAdvanceReport
{
    private const PAID_ENTRY_TYPES = ['opening_paid', 'incentive', 'commission', 'credit'];

    public function build(int $organizationId, int $branchId, ?string $from, ?string $to, ?string $companySearch = null): array
    {
        $summary = $this->emptyTotals();
        $companyTotals = [];
        $runningBalance = 0;
        $rows = [];

        if ($from) {
            $openingQuery = $this->eventsQuery($organizationId, $branchId, $from, null, $companySearch, true);
            foreach ($openingQuery->orderBy('first_date')->orderBy('first_order')->orderBy('first_id')->get() as $opening) {
                $key = mb_strtolower(trim($opening->company));
                $companyTotals[$key] ??= ['company' => trim($opening->company), ...$this->emptyTotals()];
                $event = ['advance' => (float) $opening->paid, 'received' => (float) $opening->received];
                $this->addToTotals($summary, $event, true);
                $this->addToTotals($companyTotals[$key], $event, true);
                $runningBalance += $event['advance'] - $event['received'];
            }
        }

        $events = $this->eventsQuery($organizationId, $branchId, $from, $to, $companySearch)
            ->orderBy('event_date')->orderBy('sort_order')->orderBy('id')->cursor();
        foreach ($events as $record) {
            $particular = $record->particular ?: ($record->entry_type ? $this->entryLabel($record->entry_type) : '');
            $event = $this->event($record->event_date, $record->id, $record->sort_order, $record->company,
                $particular, $record->reference, $record->installment_code, $record->product_details,
                (float) $record->advance, (float) $record->received, $record->entry_type);
            $key = mb_strtolower(trim($event['company']));
            $companyTotals[$key] ??= ['company' => trim($event['company']), ...$this->emptyTotals()];

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

    private function eventsQuery(int $organizationId, int $branchId, ?string $from, ?string $to, ?string $search, bool $opening = false): Builder
    {
        $connection = DB::connection(app(TenantContext::class)->connectionName());
        $scope = function (Builder $query, string $alias, string $date, string $company) use ($organizationId, $branchId, $from, $to, $search, $opening): Builder {
            $query->where($alias.'.organization_id', $organizationId)->where($alias.'.branch_id', $branchId);
            if ($from) {
                $query->where($date, $opening ? '<' : '>=', $from);
            }
            if ($to) {
                $query->where($date, '<=', $to);
            }
            if ($search !== null && $search !== '') {
                $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($search)).'%';
                $query->whereRaw("LOWER({$company}) LIKE ? ESCAPE '!'", [$pattern]);
            }

            return $query;
        };
        $fallbackReference = $connection->getDriverName() === 'mysql' ? "CONCAT('ADV-', a.id)" : "('ADV-' || a.id)";
        $advances = $scope($connection->table('supplier_advances as a'), 'a', 'a.advance_date', 'a.company_name')
            ->selectRaw("a.advance_date AS event_date, a.id, 1 AS sort_order, a.company_name AS company, 'Advance sent' AS particular, COALESCE(NULLIF(NULLIF(a.reference, ''), '0'), {$fallbackReference}) AS reference, '' AS installment_code, '' AS product_details, a.amount AS advance, 0 AS received, NULL AS entry_type");
        $deliveryCompany = "COALESCE(NULLIF(d.company_name, ''), a.company_name, '')";
        $deliveries = $scope($connection->table('supplier_advance_deliveries as d')
            ->leftJoin('supplier_advances as a', function ($join): void {
                $join->on('a.id', '=', 'd.supplier_advance_id')->on('a.organization_id', '=', 'd.organization_id')->on('a.branch_id', '=', 'd.branch_id');
            }), 'd', 'd.delivery_date', $deliveryCompany)
            ->selectRaw("d.delivery_date AS event_date, d.id, 2 AS sort_order, {$deliveryCompany} AS company, 'Products received' AS particular, COALESCE(NULLIF(NULLIF(a.reference, ''), '0'), {$fallbackReference}, '') AS reference, d.installment_code, d.product_details, 0 AS advance, d.amount AS received, NULL AS entry_type");
        $paidTypes = "'".implode("', '", self::PAID_ENTRY_TYPES)."'";
        $entries = $scope($connection->table('supplier_tt_entries as e'), 'e', 'e.entry_date', 'e.company_name')
            ->selectRaw("e.entry_date AS event_date, e.id, CASE WHEN e.entry_type IN ({$paidTypes}) THEN 0 ELSE 3 END AS sort_order, e.company_name AS company, e.particular, '' AS reference, '' AS installment_code, '' AS product_details, CASE WHEN e.entry_type IN ({$paidTypes}) THEN e.amount ELSE 0 END AS advance, CASE WHEN e.entry_type IN ({$paidTypes}) THEN 0 ELSE e.amount END AS received, e.entry_type");

        if ($opening) {
            $aggregate = function (Builder $query, string $company, string $date, string $id, string $order, string $paid, string $received) use ($connection): Builder {
                $query->select([])->selectRaw("{$company} AS company, SUM({$paid}) AS paid, SUM({$received}) AS received, MIN({$date}) AS first_date, MIN({$order}) AS first_order, MIN({$id}) AS first_id")
                    ->groupByRaw($company);
                $binaryCompany = $connection->getDriverName() === 'mysql' ? "CAST({$company} AS BINARY)" : "{$company} COLLATE BINARY";

                return $query->groupByRaw($binaryCompany);
            };
            $advances = $aggregate($advances, 'a.company_name', 'a.advance_date', 'a.id', '1', 'a.amount', '0');
            $deliveries = $aggregate($deliveries, $deliveryCompany, 'd.delivery_date', 'd.id', '2', '0', 'd.amount');
            $entries = $aggregate($entries, 'e.company_name', 'e.entry_date', 'e.id', "CASE WHEN e.entry_type IN ({$paidTypes}) THEN 0 ELSE 3 END",
                "CASE WHEN e.entry_type IN ({$paidTypes}) THEN e.amount ELSE 0 END", "CASE WHEN e.entry_type IN ({$paidTypes}) THEN 0 ELSE e.amount END");
        }

        return $connection->query()->fromSub($advances->unionAll($deliveries)->unionAll($entries), 'tt_events');
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
