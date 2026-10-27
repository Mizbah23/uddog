<?php

namespace App\Services;

use App\Models\SupplierAdvance;

class SupplierAdvanceReport
{
    public function build(int $organizationId, int $branchId, ?string $from, ?string $to, ?string $companySearch = null): array
    {
        $advances = SupplierAdvance::query()->where('organization_id', $organizationId)->where('branch_id', $branchId)
            ->when($companySearch, fn ($query) => $query->where('company_name', 'like', '%'.$companySearch.'%'))
            ->with('deliveries')->get();
        $events = [];
        foreach ($advances as $advance) {
            $events[] = ['date' => $advance->advance_date->toDateString(), 'id' => $advance->id, 'order' => 0,
                'company' => $advance->company_name, 'type' => 'Advance sent', 'reference' => $advance->reference ?: 'ADV-'.$advance->id,
                'installment_code' => '', 'product_details' => '', 'advance' => (float) $advance->amount, 'received' => 0, 'notes' => $advance->notes];
            foreach ($advance->deliveries as $delivery) {
                $events[] = ['date' => $delivery->delivery_date->toDateString(), 'id' => $delivery->id, 'order' => 1,
                    'company' => $advance->company_name, 'type' => 'Products received', 'reference' => $advance->reference ?: 'ADV-'.$advance->id,
                    'installment_code' => $delivery->installment_code, 'product_details' => $delivery->product_details,
                    'advance' => 0, 'received' => (float) $delivery->amount, 'notes' => $delivery->notes];
            }
        }
        usort($events, fn ($a, $b) => [$a['date'], $a['order'], $a['id']] <=> [$b['date'], $b['order'], $b['id']]);
        $opening = 0;
        $sent = 0;
        $received = 0;
        $rows = [];
        foreach ($events as $event) {
            if ($from && $event['date'] < $from) {
                $opening += $event['advance'] - $event['received'];

                continue;
            }
            if ($to && $event['date'] > $to) {
                continue;
            }
            $sent += $event['advance'];
            $received += $event['received'];
            $rows[] = ['date' => $event['date'], 'company' => $event['company'], 'particular' => $event['type'],
                'reference' => $event['reference'], 'installment_code' => $event['installment_code'], 'product_details' => $event['product_details'],
                'advance' => round($event['advance'], 2), 'received' => round($event['received'], 2),
                'balance' => round($opening + $sent - $received, 2)];
        }

        return ['columns' => ['Date', 'Company', 'Particular', 'Reference', 'Installment code', 'Product details', 'Advance sent', 'Products received', 'Balance'],
            'rows' => $rows, 'summary' => ['opening_balance' => round($opening, 2), 'advance_sent' => round($sent, 2),
                'products_received' => round($received, 2), 'closing_balance' => round($opening + $sent - $received, 2)],
            'note' => 'This TT register is independent of suppliers, purchase invoices, stock, payments, cash balances and the general ledger. Enter values here separately.'];
    }
}
