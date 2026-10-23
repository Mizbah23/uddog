<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Document;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\StockMovement;
use Illuminate\Validation\ValidationException;

class ProductLifecycleLedgerReport
{
    public function build(int $organizationId, array $branchIds, string $branchName, ?int $productId, ?string $dateFrom, ?string $dateTo): array
    {
        $empty = ['rows' => [], 'summary' => [], 'product' => null, 'branch_name' => $branchName];
        if (! $productId) {
            return $empty;
        }

        $product = Product::query()->where('organization_id', $organizationId)->findOrFail($productId);
        if ($product->stock_source_product_id) {
            throw ValidationException::withMessages(['product_id' => 'Choose the linked stock product to see the complete purchase and sales lifecycle.']);
        }
        $allBranchIds = Branch::query()->where('organization_id', $organizationId)->pluck('id')->all();
        $movements = StockMovement::query()->where('organization_id', $organizationId)
            ->where('product_id', $productId)->whereIn('branch_id', $allBranchIds)
            ->with('productDamage')->orderBy('id')->get();
        $documents = Document::query()->where('organization_id', $organizationId)
            ->whereIn('id', $movements->pluck('document_id')->filter()->unique())
            ->with(['items' => fn ($query) => $query->orderBy('id')])->get()->keyBy('id');
        $itemQueues = [];
        $lineCosts = [];
        foreach ($documents as $document) {
            $subtotal = max(1, $document->items->sum(fn ($item) => self::cents($item->line_total)));
            $remainingDiscount = self::cents($document->discount);
            foreach ($document->items->values() as $index => $item) {
                $itemQueues[$document->id.':'.($item->stock_product_id ?: $item->product_id)][] = $item;
                $discount = $index === $document->items->count() - 1
                    ? $remainingDiscount
                    : min($remainingDiscount, (int) round(self::cents($document->discount) * self::cents($item->line_total) / $subtotal));
                $remainingDiscount -= $discount;
                $lineCosts[$item->id] = self::cents($item->line_total) - $discount;
            }
        }

        $stocks = ProductStock::query()->where('organization_id', $organizationId)->where('product_id', $productId)
            ->whereIn('branch_id', $allBranchIds)->pluck('quantity_on_hand', 'branch_id');
        $states = [];
        $openingEstimated = false;
        foreach ($allBranchIds as $branchId) {
            $openingQty = self::mills($stocks[$branchId] ?? 0) - $movements->where('branch_id', $branchId)->sum(fn ($movement) => self::mills($movement->quantity_change));
            $openingCost = (int) round($openingQty * self::cents($product->cost_price) / 1000);
            $openingEstimated = $openingEstimated || $openingQty > 0;
            $states[$branchId] = [
                'quantity' => $openingQty,
                'lots' => $openingQty > 0 ? [[
                    'id' => 'opening:'.$branchId, 'reference' => 'Opening stock',
                    'quantity' => $openingQty, 'cost' => $openingCost,
                ]] : [],
            ];
        }

        $events = $movements->map(function (StockMovement $movement) use ($documents, &$itemQueues) {
            $document = $documents[$movement->document_id] ?? null;
            $queueKey = $movement->document_id.':'.$movement->product_id;
            $item = $document && ! empty($itemQueues[$queueKey]) ? array_shift($itemQueues[$queueKey]) : null;
            $at = $document
                ? $document->document_date->format('Y-m-d').' '.$movement->created_at->format('H:i:s.u')
                : $movement->created_at->format('Y-m-d H:i:s.u');

            return compact('movement', 'document', 'item', 'at');
        })->sort(fn ($a, $b) => strcmp($a['at'], $b['at']) ?: $a['movement']->id <=> $b['movement']->id)->values();

        $saleSlices = [];
        $transferSlices = [];
        $costEstimated = false;
        $negativeBalance = false;
        $rows = [];
        $revenue = $cogs = 0;
        $previousPurchasePrice = $previousSalePrice = null;
        $period = ['purchased_quantity' => 0, 'sold_quantity' => 0, 'returned_quantity' => 0, 'sales' => 0, 'cogs' => 0, 'profit' => 0];
        $openingSnapshot = $dateFrom ? null : $this->snapshot($states, $branchIds, $revenue, $cogs);
        foreach ($events as $event) {
            $day = substr($event['at'], 0, 10);
            if ($dateTo && $day > $dateTo) {
                break;
            }
            $movement = $event['movement'];
            $document = $event['document'];
            $item = $event['item'];
            $type = $movement->type;
            $branchId = $movement->branch_id;
            $visible = in_array($branchId, $branchIds, true);
            $state = &$states[$branchId];
            $quantity = abs(self::mills($movement->quantity_change));
            $unitPrice = $item ? self::cents($item->unit_price) : null;
            $amount = $item ? self::cents($item->line_total) : 0;
            $allocations = [];
            $eventRevenue = $eventCogs = 0;
            $priceChange = $priceChangePercent = null;

            if ($dateFrom && $openingSnapshot === null && substr($event['at'], 0, 10) >= $dateFrom) {
                $openingSnapshot = $this->snapshot($states, $branchIds, $revenue, $cogs);
            }

            if ($type === 'purchase') {
                $cost = $item ? $lineCosts[$item->id] : $amount;
                $lotId = 'purchase:'.($item?->id ?? $movement->id);
                $state['lots'][] = ['id' => $lotId, 'reference' => $document?->number ?? 'Purchase', 'quantity' => $quantity, 'cost' => $cost];
                if ($visible) {
                    [$priceChange, $priceChangePercent] = $this->change($previousPurchasePrice, $unitPrice);
                    $previousPurchasePrice = $unitPrice;
                }
                $allocations[] = ['lot' => $lotId, 'reference' => $document?->number ?? 'Purchase', 'quantity' => self::units($quantity), 'cost' => $cost / 100];
            } elseif (in_array($type, ['sale', 'resale'], true)) {
                $allocations = $this->consume($state['lots'], $quantity, self::cents($item?->cost_price ?? 0));
                $eventCogs = array_sum(array_column($allocations, 'cost'));
                $eventRevenue = $item ? ($lineCosts[$item->id] ?? $amount) : $amount;
                foreach ($allocations as $allocation) {
                    $saleSlices[$document?->id.':'.$item?->product_id][] = [
                        'lot' => $allocation['lot'], 'reference' => $allocation['reference'],
                        'quantity' => self::mills($allocation['quantity']), 'cost' => $allocation['cost'],
                    ];
                }
                if ($visible) {
                    [$priceChange, $priceChangePercent] = $this->change($previousSalePrice, $unitPrice);
                    $previousSalePrice = $unitPrice;
                }
            } elseif ($type === 'sale_return') {
                $remaining = $quantity;
                $saleKey = $document?->sale_id.':'.$item?->product_id;
                $saleSlices[$saleKey] ??= [];
                foreach ($saleSlices[$saleKey] as &$slice) {
                    if ($remaining <= 0 || $slice['quantity'] <= 0) {
                        continue;
                    }
                    $take = min($remaining, $slice['quantity']);
                    $cost = $take === $slice['quantity'] ? $slice['cost'] : (int) round($slice['cost'] * $take / $slice['quantity']);
                    $slice['quantity'] -= $take;
                    $slice['cost'] -= $cost;
                    $remaining -= $take;
                    $this->restore($state['lots'], $slice['lot'], $slice['reference'], $take, $cost);
                    $allocations[] = ['lot' => $slice['lot'], 'reference' => $slice['reference'], 'quantity' => self::units($take), 'cost' => $cost];
                }
                unset($slice);
                if ($remaining > 0) {
                    $cost = (int) round($remaining * self::cents($item?->cost_price ?? 0) / 1000);
                    $this->restore($state['lots'], 'unmatched-return:'.$movement->id, 'Unmatched return', $remaining, $cost);
                    $allocations[] = ['lot' => 'unmatched-return:'.$movement->id, 'reference' => 'Unmatched return', 'quantity' => self::units($remaining), 'cost' => $cost];
                    $costEstimated = true;
                }
                $eventCogs = -array_sum(array_column($allocations, 'cost'));
                $eventRevenue = -$amount;
            } elseif ($type === 'transfer_in') {
                $remaining = $quantity;
                $transferSlices[$movement->stock_transfer_id] ??= [];
                foreach ($transferSlices[$movement->stock_transfer_id] as &$slice) {
                    $take = min($remaining, $slice['quantity']);
                    if ($take <= 0) {
                        continue;
                    }
                    $cost = (int) round($slice['cost'] * $take / $slice['quantity']);
                    $slice['quantity'] -= $take;
                    $slice['cost'] -= $cost;
                    $this->restore($state['lots'], $slice['lot'], $slice['reference'], $take, $cost);
                    $allocations[] = ['lot' => $slice['lot'], 'reference' => $slice['reference'], 'quantity' => self::units($take), 'cost' => $cost];
                    $remaining -= $take;
                }
                unset($slice);
                if ($remaining > 0) {
                    $this->restore($state['lots'], 'transfer:'.$movement->id, 'Transfer', $remaining, 0);
                    $costEstimated = true;
                }
            } elseif (self::mills($movement->quantity_change) > 0) {
                $cost = (int) round($quantity * self::cents($product->cost_price) / 1000);
                $this->restore($state['lots'], $type.':'.$movement->id, ucfirst(str_replace('_', ' ', $type)), $quantity, $cost);
            } else {
                $sourcePurchaseId = $type === 'purchase_return' ? $document?->purchase_id : $movement->productDamage?->purchase_id;
                $sourceItemId = $sourcePurchaseId ? $this->purchaseItemId($documents[$sourcePurchaseId] ?? null, $productId) : null;
                $preferred = $sourceItemId ? 'purchase:'.$sourceItemId : null;
                $allocations = $this->consume($state['lots'], $quantity, self::cents($item?->cost_price ?? $product->cost_price), $preferred);
                if ($type === 'transfer_out') {
                    foreach ($allocations as $allocation) {
                        $transferSlices[$movement->stock_transfer_id][] = [
                            ...$allocation, 'quantity' => self::mills($allocation['quantity']),
                        ];
                    }
                }
            }

            $costEstimated = $costEstimated || collect($allocations)->contains(fn ($allocation) => $allocation['lot'] === 'unattributed');

            $state['quantity'] += self::mills($movement->quantity_change);
            $negativeBalance = $negativeBalance || $state['quantity'] < 0;
            if ($visible) {
                $revenue += $eventRevenue;
                $cogs += $eventCogs;
            }
            if (! $visible || ($dateFrom && $day < $dateFrom)) {
                unset($state);

                continue;
            }
            $profit = $eventRevenue - $eventCogs;
            if ($type === 'purchase') {
                $period['purchased_quantity'] += $quantity;
            } elseif ($type === 'purchase_return') {
                $period['purchased_quantity'] -= $quantity;
            } elseif (in_array($type, ['sale', 'resale'], true)) {
                $period['sold_quantity'] += $quantity;
            } elseif ($type === 'sale_return') {
                $period['returned_quantity'] += $quantity;
            }
            $period['sales'] += $eventRevenue;
            $period['cogs'] += $eventCogs;
            $period['profit'] += $profit;
            if (in_array($type, ['sale', 'resale', 'sale_return'], true)) {
                $this->distributeRevenue($allocations, $eventRevenue, $type === 'sale_return');
            }
            $rows[] = [
                'at' => $event['at'], 'type' => $type, 'reference' => $document?->number ?? $movement->notes ?? ucfirst(str_replace('_', ' ', $type)),
                'branch_id' => $branchId, 'quantity' => self::units(self::mills($movement->quantity_change)),
                'unit_price' => $unitPrice === null ? null : $unitPrice / 100,
                'amount' => (in_array($type, ['purchase', 'purchase_return'], true) ? $amount * ($type === 'purchase_return' ? -1 : 1) : $eventRevenue) / 100,
                'price_change' => $priceChange, 'price_change_percent' => $priceChangePercent,
                'allocations' => array_map(fn ($allocation) => [
                    ...$allocation,
                    'cost' => $allocation['cost'] / 100,
                    'revenue' => isset($allocation['revenue']) ? $allocation['revenue'] / 100 : null,
                    'profit' => isset($allocation['profit']) ? $allocation['profit'] / 100 : null,
                    'margin' => $allocation['margin'] ?? null,
                ], $allocations),
                'revenue' => $eventRevenue / 100, 'cogs' => $eventCogs / 100, 'profit' => $profit / 100,
                'margin' => $eventRevenue !== 0 ? round($profit / abs($eventRevenue) * 100, 2) : null,
                'stock_balance' => self::units(array_sum(array_column(array_intersect_key($states, array_flip($branchIds)), 'quantity'))),
                'stock_value' => $this->valuation(array_intersect_key($states, array_flip($branchIds))) / 100,
                'cumulative_sales' => $revenue / 100, 'cumulative_cogs' => $cogs / 100,
                'cumulative_profit' => ($revenue - $cogs) / 100,
            ];
            unset($state);
        }

        $openingSnapshot ??= $this->snapshot($states, $branchIds, $revenue, $cogs);
        $closing = $this->snapshot($states, $branchIds, $revenue, $cogs);

        return [
            'product' => ['id' => $product->id, 'name' => $product->name, 'sku' => $product->sku, 'unit' => $product->unit],
            'branch_name' => $branchName,
            'columns' => ['Date & time', 'Movement', 'Document', 'Branch', 'Quantity', 'Unit price', 'Price change', 'Price change %', 'Amount', 'FIFO COGS', 'Gross profit', 'Margin', 'Stock balance', 'Stock valuation', 'Cumulative sales', 'Cumulative COGS', 'Cumulative profit', 'Lot allocations'],
            'rows' => $rows,
            'summary' => [
                'opening_stock' => $openingSnapshot['stock'], 'opening_value' => $openingSnapshot['value'],
                'purchased_quantity' => self::units($period['purchased_quantity']),
                'sold_quantity' => self::units($period['sold_quantity'] - $period['returned_quantity']),
                'sales' => $period['sales'] / 100, 'cogs' => $period['cogs'] / 100,
                'realized_profit' => $period['profit'] / 100,
                'closing_stock' => $closing['stock'], 'closing_value' => $closing['value'],
                'cumulative_sales' => $closing['revenue'], 'cumulative_cogs' => $closing['cogs'],
                'cumulative_profit' => $closing['profit'],
            ],
            'notes' => array_values(array_filter([
                'Business dates come from documents; times are when stock movements were recorded.',
                'Damage and other non-sale stock movements change stock valuation but do not change realized sales profit.',
                $openingEstimated ? 'Opening stock uses the current product cost as an estimated basis because historic opening lot costs were not recorded.' : null,
                $costEstimated ? 'Some stock has no matching purchase lot; its cost is estimated from the recorded item cost.' : null,
                $negativeBalance ? 'A backdated movement produced a negative interim stock balance; review the transaction dates.' : null,
            ])),
        ];
    }

    private function consume(array &$lots, int $quantity, int $fallbackUnitCost, ?string $preferred = null): array
    {
        $allocations = [];
        $remaining = $quantity;
        foreach ($lots as &$lot) {
            if ($preferred !== null && $lot['id'] !== $preferred) {
                continue;
            }
            $this->take($lot, $remaining, $allocations);
        }
        unset($lot);
        foreach ($lots as &$lot) {
            if ($remaining <= 0) {
                break;
            }
            $this->take($lot, $remaining, $allocations);
        }
        unset($lot);
        if ($remaining > 0) {
            $allocations[] = ['lot' => 'unattributed', 'reference' => 'Unattributed stock', 'quantity' => self::units($remaining), 'cost' => (int) round($remaining * $fallbackUnitCost / 1000)];
        }

        return $allocations;
    }

    private function take(array &$lot, int &$remaining, array &$allocations): void
    {
        if ($remaining <= 0 || $lot['quantity'] <= 0) {
            return;
        }
        $taken = min($remaining, $lot['quantity']);
        $cost = $taken === $lot['quantity'] ? $lot['cost'] : (int) round($lot['cost'] * $taken / $lot['quantity']);
        $lot['quantity'] -= $taken;
        $lot['cost'] -= $cost;
        $remaining -= $taken;
        $allocations[] = ['lot' => $lot['id'], 'reference' => $lot['reference'], 'quantity' => self::units($taken), 'cost' => $cost];
    }

    private function restore(array &$lots, string $id, string $reference, int $quantity, int $cost): void
    {
        foreach ($lots as &$lot) {
            if ($lot['id'] === $id) {
                $lot['quantity'] += $quantity;
                $lot['cost'] += $cost;

                return;
            }
        }
        unset($lot);
        $lots[] = compact('id', 'reference', 'quantity', 'cost');
    }

    private function distributeRevenue(array &$allocations, int $revenue, bool $returned): void
    {
        $totalQuantity = array_sum(array_map(fn ($allocation) => self::mills($allocation['quantity']), $allocations));
        $remainingRevenue = $revenue;
        foreach ($allocations as $index => &$allocation) {
            $lineRevenue = $index === array_key_last($allocations)
                ? $remainingRevenue
                : (int) round($revenue * self::mills($allocation['quantity']) / max(1, $totalQuantity));
            $remainingRevenue -= $lineRevenue;
            $signedCost = $returned ? -$allocation['cost'] : $allocation['cost'];
            $allocation['revenue'] = $lineRevenue;
            $allocation['profit'] = $lineRevenue - $signedCost;
            $allocation['margin'] = $lineRevenue !== 0 ? round($allocation['profit'] / abs($lineRevenue) * 100, 2) : null;
        }
        unset($allocation);
    }

    private function snapshot(array $states, array $branchIds, int $revenue, int $cogs): array
    {
        $selectedStates = array_intersect_key($states, array_flip($branchIds));

        return [
            'stock' => self::units(array_sum(array_column($selectedStates, 'quantity'))),
            'value' => $this->valuation($selectedStates) / 100,
            'revenue' => $revenue / 100, 'cogs' => $cogs / 100, 'profit' => ($revenue - $cogs) / 100,
        ];
    }

    private function valuation(array $states): int
    {
        return array_sum(array_map(fn ($state) => array_sum(array_column($state['lots'], 'cost')), $states));
    }

    private function change(?int $previous, ?int $current): array
    {
        if ($previous === null || $current === null) {
            return [null, null];
        }
        $delta = $current - $previous;

        return [$delta / 100, $previous !== 0 ? round($delta / $previous * 100, 2) : null];
    }

    private function purchaseItemId(?Document $purchase, int $productId): ?int
    {
        return $purchase?->items->firstWhere('product_id', $productId)?->id;
    }

    private static function cents(mixed $value): int
    {
        return (int) round((float) $value * 100);
    }

    private static function mills(mixed $value): int
    {
        return (int) round((float) $value * 1000);
    }

    private static function units(int $mills): float
    {
        return round($mills / 1000, 3);
    }
}
