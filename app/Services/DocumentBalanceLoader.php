<?php

namespace App\Services;

use App\Models\Document;
use Illuminate\Support\Collection;

class DocumentBalanceLoader
{
    /** @param Collection<int, Document> $documents */
    public function load(Collection $documents): void
    {
        $sales = $documents->filter(fn (Document $document): bool => in_array($document->type, ['sale', 'resale'], true));
        foreach ($sales->groupBy('organization_id') as $organizationId => $group) {
            $balances = $this->forContacts((int) $organizationId, $group->pluck('contact_id')->unique()->all());
            foreach ($group as $sale) {
                $sale->setBalanceDueForSerialization($balances[$sale->id] ?? 0);
            }
        }
    }

    /**
     * Read the full customer history, including invoices outside the requested page or branch.
     *
     * @param  array<int, int|null>  $contactIds
     * @return array<int, int>
     */
    public function forContacts(int $organizationId, array $contactIds): array
    {
        $query = Document::query()->where('organization_id', $organizationId)
            ->where(function ($query) use ($contactIds): void {
                $query->whereIn('contact_id', array_filter($contactIds, fn ($id): bool => $id !== null));
                if (in_array(null, $contactIds, true)) {
                    $query->orWhereNull('contact_id');
                }
            });
        $sales = (clone $query)->whereIn('type', ['sale', 'resale'])
            ->orderBy('document_date')->orderBy('id')
            ->toBase()->get(['id', 'contact_id', 'total', 'amount_paid']);
        $returnsByContact = (clone $query)->where('type', 'sale_return')
            ->where(fn ($query) => $query->where('payment_method', 'credit')->orWhereNull('payment_method'))
            ->toBase()->get(['contact_id', 'sale_id', 'total'])->groupBy('contact_id');
        $result = [];
        foreach ($sales->groupBy('contact_id') as $contactId => $customerSales) {
            $balances = [];
            foreach ($customerSales as $sale) {
                $balances[$sale->id] = max(0, (int) round((float) $sale->total * 100) - (int) round((float) $sale->amount_paid * 100));
            }
            $credit = 0;
            foreach ($returnsByContact->get($contactId, collect()) as $return) {
                $amount = (int) round((float) $return->total * 100);
                $applied = min($amount, $balances[$return->sale_id] ?? 0);
                if (isset($balances[$return->sale_id])) {
                    $balances[$return->sale_id] -= $applied;
                }
                $credit += $amount - $applied;
            }
            foreach ($balances as $id => $balance) {
                $applied = min($credit, $balance);
                $result[$id] = $balance - $applied;
                $credit -= $applied;
            }
        }

        return $result;
    }
}
