<?php

namespace App\Services;

use App\Models\Document;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryService
{
    public function createDocument(array $data, int $userId, int $organizationId, int $branchId): Document
    {
        return DB::transaction(function () use ($data, $userId, $organizationId, $branchId) {
            $type = $data['type'];
            $purchase = null;
            $sale = null;
            if ($type === 'purchase_return') {
                $purchase = Document::query()->where('organization_id', $organizationId)->lockForUpdate()->find($data['purchase_id']);
                if (! $purchase || $purchase->type !== 'purchase' || (int) $purchase->branch_id !== $branchId || (int) $purchase->contact_id !== (int) $data['contact_id']) {
                    throw ValidationException::withMessages(['purchase_id' => 'Select a purchase for the same supplier.']);
                }
            }
            if ($type === 'sale_return') {
                $sale = Document::query()->where('organization_id', $organizationId)->lockForUpdate()->find($data['sale_id']);
                if (! $sale || ! in_array($sale->type, ['sale', 'resale'], true) || (int) $sale->branch_id !== $branchId || (int) $sale->contact_id !== (int) $data['contact_id']) {
                    throw ValidationException::withMessages(['sale_id' => 'Select a sale for the same customer.']);
                }
            }

            $items = [];
            $subtotalCents = 0;
            foreach ($data['items'] as $item) {
                $product = Product::query()->where('organization_id', $organizationId)->lockForUpdate()->find($item['product_id']);
                if (! $product || (! $product->active && ! in_array($type, ['purchase_return', 'sale_return'], true))) {
                    throw ValidationException::withMessages(['items' => 'Every product must be active.']);
                }
                $quantity = self::scaled($item['quantity'], 3);
                $unitCents = self::scaled($item['unit_price'], 2);
                $sourceCostCents = null;
                if ($sale) {
                    $soldItem = $sale->items()->where('product_id', $product->id)->first();
                    if (! $soldItem) {
                        throw ValidationException::withMessages(['items' => "{$product->name} was not included in the selected sale."]);
                    }
                    $unitCents = self::scaled($soldItem->unit_price, 2);
                    $sourceCostCents = self::scaled($soldItem->cost_price, 2);
                }
                $lineCents = intdiv($quantity * $unitCents + 500, 1000);
                $subtotalCents += $lineCents;
                $items[] = [$product, $quantity, $unitCents, $lineCents, $sourceCostCents];
            }

            $discountCents = $type === 'sale_return' ? 0 : self::scaled($data['discount'] ?? 0, 2);
            $taxCents = $type === 'sale_return' ? 0 : self::scaled($data['tax'] ?? 0, 2);
            if ($discountCents > $subtotalCents) {
                throw ValidationException::withMessages(['discount' => 'Discount cannot exceed the subtotal.']);
            }
            $totalCents = $subtotalCents - $discountCents + $taxCents;
            $amountPaidCents = self::scaled($data['amount_paid'] ?? 0, 2);
            $saleChannel = $data['sale_channel'] ?? 'standard';
            if ($saleChannel === 'pos' && $amountPaidCents > $totalCents) {
                throw ValidationException::withMessages(['amount_paid' => 'Amount paid cannot exceed the sale total.']);
            }

            $document = Document::create([
                'organization_id' => $organizationId,
                'branch_id' => $branchId,
                'number' => 'TMP-'.bin2hex(random_bytes(12)),
                'type' => $type,
                'sale_channel' => $saleChannel,
                'contact_id' => $data['contact_id'],
                'purchase_id' => $purchase?->id,
                'sale_id' => $sale?->id,
                'document_date' => $data['document_date'],
                'subtotal' => self::money($subtotalCents),
                'discount' => self::money($discountCents),
                'tax' => self::money($taxCents),
                'total' => self::money($totalCents),
                'payment_method' => $data['payment_method'] ?? null,
                'amount_paid' => self::money($amountPaidCents),
                'notes' => $data['notes'] ?? null,
                'created_by' => $userId,
            ]);
            $prefix = ['purchase' => 'PUR', 'sale' => 'SAL', 'resale' => 'RSL', 'purchase_return' => 'PRT', 'sale_return' => 'SRT'][$type];
            $document->update(['number' => $prefix.'-'.str_pad((string) $document->id, 6, '0', STR_PAD_LEFT)]);

            foreach ($items as [$product, $quantity, $unitCents, $lineCents, $sourceCostCents]) {
                if ($purchase) {
                    $purchased = self::scaled($purchase->items()->where('product_id', $product->id)->sum('quantity'), 3);
                    $returned = self::scaled(DB::table('document_items')
                        ->join('documents', 'documents.id', '=', 'document_items.document_id')
                        ->where('documents.purchase_id', $purchase->id)
                        ->where('document_items.product_id', $product->id)
                        ->sum('document_items.quantity'), 3);
                    if ($quantity > $purchased - $returned) {
                        throw ValidationException::withMessages(['items' => "Return quantity exceeds the remaining purchased quantity for {$product->name}."]);
                    }
                }
                if ($sale) {
                    $sold = self::scaled($sale->items()->where('product_id', $product->id)->sum('quantity'), 3);
                    $returned = self::scaled(DB::table('document_items')
                        ->join('documents', 'documents.id', '=', 'document_items.document_id')
                        ->where('documents.sale_id', $sale->id)
                        ->where('document_items.product_id', $product->id)
                        ->sum('document_items.quantity'), 3);
                    if ($quantity > $sold - $returned) {
                        throw ValidationException::withMessages(['items' => "Return quantity exceeds the remaining sold quantity for {$product->name}."]);
                    }
                }

                ProductStock::query()->firstOrCreate([
                    'organization_id' => $organizationId,
                    'branch_id' => $branchId,
                    'product_id' => $product->id,
                ], ['quantity_on_hand' => 0]);
                $branchStock = ProductStock::query()->where('branch_id', $branchId)->where('product_id', $product->id)->lockForUpdate()->firstOrFail();
                $branchQuantity = self::scaled($branchStock->quantity_on_hand, 3);
                $aggregateQuantity = self::scaled($product->quantity_on_hand, 3);
                $currentCostCents = self::scaled($product->cost_price, 2);
                $capturedCostCents = $sourceCostCents ?? $currentCostCents;
                $capturedCost = self::money($capturedCostCents);
                $change = in_array($type, ['purchase', 'sale_return'], true) ? $quantity : -$quantity;
                $branchBalance = $branchQuantity + $change;
                $aggregateBalance = $aggregateQuantity + $change;
                if ($branchBalance < 0) {
                    throw ValidationException::withMessages(['items' => "Insufficient stock for {$product->name}."]);
                }
                $productUpdate = ['quantity_on_hand' => self::quantity($aggregateBalance)];
                if ($type === 'purchase') {
                    $averageCostCents = intdiv(($aggregateQuantity * $currentCostCents) + ($quantity * $unitCents) + intdiv($aggregateBalance, 2), $aggregateBalance);
                    $productUpdate['cost_price'] = self::money($averageCostCents);
                } elseif ($type === 'purchase_return') {
                    $remainingValue = ($aggregateQuantity * $currentCostCents) - ($quantity * $unitCents);
                    $averageCostCents = $aggregateBalance > 0 ? max(0, intdiv($remainingValue + intdiv($aggregateBalance, 2), $aggregateBalance)) : 0;
                    $productUpdate['cost_price'] = self::money($averageCostCents);
                } elseif ($type === 'sale_return') {
                    $averageCostCents = intdiv(($aggregateQuantity * $currentCostCents) + ($quantity * $capturedCostCents) + intdiv($aggregateBalance, 2), $aggregateBalance);
                    $productUpdate['cost_price'] = self::money($averageCostCents);
                }
                $product->update($productUpdate);
                $branchStock->update(['quantity_on_hand' => self::quantity($branchBalance)]);
                $document->items()->create([
                    'product_id' => $product->id,
                    'quantity' => self::quantity($quantity),
                    'unit_price' => self::money($unitCents),
                    'cost_price' => $capturedCost,
                    'line_total' => self::money($lineCents),
                ]);
                StockMovement::create([
                    'organization_id' => $organizationId,
                    'branch_id' => $branchId,
                    'product_id' => $product->id,
                    'document_id' => $document->id,
                    'type' => $type,
                    'quantity_change' => self::quantity($change),
                    'balance_after' => self::quantity($branchBalance),
                    'created_by' => $userId,
                ]);
            }

            return $document->load('branch', 'contact', 'items.product', 'purchase', 'sale', 'creator:id,name');
        });
    }

    public function adjust(Product $product, string $change, ?string $notes, int $userId, int $organizationId, int $branchId): StockMovement
    {
        return DB::transaction(function () use ($product, $change, $notes, $userId, $organizationId, $branchId) {
            $locked = Product::query()->where('organization_id', $organizationId)->lockForUpdate()->findOrFail($product->id);
            ProductStock::query()->firstOrCreate([
                'organization_id' => $organizationId,
                'branch_id' => $branchId,
                'product_id' => $locked->id,
            ], ['quantity_on_hand' => 0]);
            $branchStock = ProductStock::query()->where('branch_id', $branchId)->where('product_id', $locked->id)->lockForUpdate()->firstOrFail();
            $delta = self::scaled($change, 3);
            $balance = self::scaled($branchStock->quantity_on_hand, 3) + $delta;
            $aggregateBalance = self::scaled($locked->quantity_on_hand, 3) + $delta;
            if ($delta === 0 || $balance < 0) {
                throw ValidationException::withMessages(['quantity_change' => 'Adjustment must be nonzero and cannot make stock negative.']);
            }
            $locked->update(['quantity_on_hand' => self::quantity($aggregateBalance)]);
            $branchStock->update(['quantity_on_hand' => self::quantity($balance)]);

            return StockMovement::create([
                'organization_id' => $organizationId,
                'branch_id' => $branchId,
                'product_id' => $locked->id,
                'type' => 'adjustment',
                'quantity_change' => self::quantity($delta),
                'balance_after' => self::quantity($balance),
                'notes' => $notes,
                'created_by' => $userId,
            ])->load('product');
        });
    }

    private static function scaled(string|int|float $value, int $places): int
    {
        $string = (string) $value;
        $negative = str_starts_with($string, '-');
        $string = ltrim($string, '-');
        [$whole, $fraction] = array_pad(explode('.', $string, 2), 2, '');
        $factor = 10 ** $places;
        $scaled = ((int) $whole * $factor) + (int) str_pad(substr($fraction, 0, $places), $places, '0');

        return $negative ? -$scaled : $scaled;
    }

    private static function money(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    private static function quantity(int $mills): string
    {
        return number_format($mills / 1000, 3, '.', '');
    }
}
