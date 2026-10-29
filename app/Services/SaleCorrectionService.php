<?php

namespace App\Services;

use App\Models\Document;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\StockCheckItem;
use App\Models\StockMovement;
use App\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaleCorrectionService
{
    private const MAX_SCALED = 99999999999999;

    public function correct(int $documentId, int $organizationId, int $branchId, int $userId, array $data): Document
    {
        return DB::connection(app(TenantContext::class)->connectionName())->transaction(function () use ($documentId, $organizationId, $branchId, $userId, $data): Document {
            $sale = Document::query()->where('organization_id', $organizationId)->where('branch_id', $branchId)
                ->whereIn('type', ['sale', 'resale'])->lockForUpdate()->findOrFail($documentId);
            $sale->setRelation('items', $sale->items()->with('product')->orderBy('id')->lockForUpdate()->get());
            if (! hash_equals($sale->correction_version, $data['correction_version'])) {
                $this->reject('This sale changed after you opened it. Reopen the correction and review the latest values.');
            }
            if ($sale->payment_type === 'installment' || $sale->installments()->exists()
                || (float) $sale->interest_amount > 0 || (float) $sale->gold_exchange_value > 0
                || $sale->items->contains(fn ($item) => $item->product?->is_gold_item || $item->gold_purity !== null || $item->gold_gross_weight !== null)) {
                $this->reject('Installment and gold sales require separate reconciliation and cannot be corrected here.');
            }
            if (Document::query()->where('organization_id', $organizationId)->where('sale_id', $sale->id)->exists()) {
                $this->reject('This sale has linked returns. Resolve those returns before correcting it.');
            }
            $submitted = collect($data['items'])->keyBy('id');
            if ($submitted->count() !== $sale->items->count()
                || $sale->items->pluck('id')->diff($submitted->keys())->isNotEmpty()) {
                $this->reject('Include every original sale item exactly once. Products cannot be added or removed here.');
            }
            $before = $sale->correctionSnapshot();
            $movements = StockMovement::query()->where('organization_id', $organizationId)->where('branch_id', $branchId)
                ->where('document_id', $sale->id)->orderBy('id')->lockForUpdate()->get();
            if ($movements->count() !== $sale->items->count()) {
                $this->reject('The stock history for this sale needs review before correction.');
            }
            $movementQueues = $movements->groupBy('product_id')->map(fn ($group) => $group->values());
            $subtotal = 0;
            $stockChanges = [];
            foreach ($sale->items as $item) {
                $input = $submitted[$item->id];
                $conversion = max(1, (int) ($item->unit_conversion ?? 1));
                $enteredQuantity = $this->scaled($input['quantity'], 3);
                $quantity = $enteredQuantity * $conversion;
                if ($quantity > self::MAX_SCALED || ($item->product->usesWholePieces() && $quantity % 1000 !== 0)) {
                    $this->reject('Enter a supported quantity in whole pieces for piece products.');
                }
                $price = $this->scaled($input['unit_price'], 2);
                $lineTotal = (int) round($enteredQuantity / 1000 * $price);
                $subtotal += $lineTotal;
                if ($subtotal > self::MAX_SCALED) {
                    $this->reject('The corrected invoice amount exceeds the supported range.');
                }
                $stockProductId = $item->stock_product_id ?: $item->product_id;
                $oldQuantity = $this->scaled($item->quantity, 3);
                $oldStockQuantity = $this->scaled($item->stock_quantity ?? $item->quantity, 3);
                if ($oldQuantity <= 0 || $oldStockQuantity <= 0) {
                    $this->reject('The stock history for this sale needs review before correction.');
                }
                $newStockQuantity = (int) round($quantity / $oldQuantity * $oldStockQuantity);
                if ($newStockQuantity < 1 || $newStockQuantity > self::MAX_SCALED) {
                    $this->reject('The corrected stock quantity exceeds the supported range.');
                }
                $movement = $movementQueues->get($stockProductId)?->shift();
                if (! $movement || $movement->type !== $sale->type
                    || $this->scaled($movement->quantity_change, 3) !== -$oldStockQuantity) {
                    $this->reject('The stock history for this sale needs review before correction.');
                }
                $delta = $oldStockQuantity - $newStockQuantity;
                if ($delta !== 0) {
                    $stockChanges[] = [
                        'product_id' => $stockProductId, 'movement_id' => $movement->id,
                        'quantity_change' => $this->decimal($delta, 3),
                        'old_quantity_change' => $movement->quantity_change,
                        'new_quantity_change' => $this->decimal(-$newStockQuantity, 3),
                        'cost_change' => $delta / 1000 * ($this->scaled($item->cost_price, 2) * $oldQuantity / $oldStockQuantity),
                    ];
                }
                $item->fill([
                    'entered_quantity' => $this->decimal($enteredQuantity, 3),
                    'quantity' => $this->decimal($quantity, 3),
                    'stock_quantity' => $this->decimal($newStockQuantity, 3),
                    'entered_unit_price' => $this->decimal($price, 2),
                    'unit_price' => $this->decimal(intdiv($price + intdiv($conversion, 2), $conversion), 2),
                    'line_total' => $this->decimal($lineTotal, 2),
                ]);
            }
            $discount = $this->scaled($data['discount'], 2);
            $total = $subtotal - $discount + $this->scaled($data['tax'], 2);
            if ($discount > $subtotal || $total > self::MAX_SCALED) {
                $this->reject('Discount cannot exceed subtotal and the total must be within the supported range.');
            }
            if (array_key_exists('recorded_amount_paid', $data)) {
                $recordedPaid = $this->scaled($data['recorded_amount_paid'], 2);
                $originalPaid = $this->scaled($sale->amount_paid, 2);
                if ($originalPaid <= 0 || $sale->payments()->exists() || $recordedPaid > $originalPaid) {
                    $this->reject('Only a recorded paid total without individual payment entries can be reduced here. Manage individual payments from the invoice.');
                }
                $sale->amount_paid = $this->decimal($recordedPaid, 2);
                $sale->down_payment = $this->decimal(min($this->scaled($sale->down_payment, 2), $recordedPaid), 2);
            }
            if ($this->scaled($sale->amount_paid, 2) > $total) {
                $this->reject('Payments exceed the corrected total. Remove the excess related payments before correcting this sale.');
            }
            $sale->fill([
                'subtotal' => $this->decimal($subtotal, 2), 'discount' => $this->decimal($discount, 2),
                'tax' => $this->decimal($this->scaled($data['tax'], 2), 2), 'total' => $this->decimal($total, 2),
            ]);
            if ($before === $sale->correctionSnapshot()) {
                $this->reject('Change at least one quantity, price, discount or tax before saving a correction.');
            }
            if ($sale->payment_type === 'full' && $this->scaled($sale->amount_paid, 2) < $total) {
                $sale->payment_type = 'due';
            }
            $this->reconcileStock($sale, $stockChanges, $organizationId, $branchId);
            foreach ($sale->items as $item) {
                $item->save();
            }
            $sale->save();
            $sale->corrections()->create([
                'organization_id' => $organizationId, 'branch_id' => $branchId, 'created_by' => $userId,
                'reason' => trim($data['reason']), 'before' => $before, 'after' => $sale->correctionSnapshot(),
                'stock_changes' => $stockChanges,
            ]);

            return $sale->fresh()->load('branch', 'contact', 'items.product', 'creator:id,name', 'payments.receiver', 'installments', 'corrections.creator:id,name');
        });
    }

    private function reconcileStock(Document $sale, array &$stockChanges, int $organizationId, int $branchId): void
    {
        foreach (collect($stockChanges)->groupBy('product_id')->sortKeys() as $productId => $changes) {
            $product = Product::query()->where('organization_id', $organizationId)->lockForUpdate()->findOrFail($productId);
            $stock = ProductStock::query()->where('organization_id', $organizationId)->where('branch_id', $branchId)
                ->where('product_id', $productId)->lockForUpdate()->firstOrFail();
            if (StockCheckItem::query()->where('product_id', $productId)
                ->whereHas('stockCheck', fn ($query) => $query->where('organization_id', $organizationId)
                    ->where('branch_id', $branchId)->where('created_at', '>=', $sale->created_at))->lockForUpdate()->get()->isNotEmpty()) {
                $this->reject('A later physical stock check exists for this product. Reconcile that count before correcting the quantity.');
            }
            $delta = $changes->sum(fn ($change) => $this->scaled($change['quantity_change'], 3));
            $branchQuantity = $this->scaled($stock->quantity_on_hand, 3) + $delta;
            $totalQuantity = $this->scaled($product->quantity_on_hand, 3) + $delta;
            if (min($branchQuantity, $totalQuantity) < 0 || max($branchQuantity, $totalQuantity) > self::MAX_SCALED) {
                $this->reject('The correction would make stock negative or exceed the supported range.');
            }
            $changesByMovement = $changes->keyBy('movement_id');
            $laterMovements = StockMovement::query()->where('organization_id', $organizationId)->where('branch_id', $branchId)
                ->where('product_id', $productId)->where('id', '>=', $changes->min('movement_id'))
                ->orderBy('id')->lockForUpdate()->get();
            $runningDelta = 0;
            foreach ($laterMovements as $movement) {
                $change = $changesByMovement->get($movement->id);
                if ($change) {
                    $runningDelta += $this->scaled($change['quantity_change'], 3);
                    $movement->quantity_change = $change['new_quantity_change'];
                }
                $balance = $this->scaled($movement->balance_after, 3) + $runningDelta;
                if ($balance < 0 || $balance > self::MAX_SCALED) {
                    $this->reject('The correction would make a later stock balance negative or exceed the supported range.');
                }
                $movement->balance_after = $this->decimal($balance, 3);
                $movement->save();
            }
            $costValue = $this->scaled($product->quantity_on_hand, 3) / 1000 * $this->scaled($product->cost_price, 2)
                + $changes->sum('cost_change');
            if ($costValue < 0) {
                $this->reject('Stock valuation needs review before this quantity can be corrected.');
            }
            $cost = $totalQuantity > 0 ? (int) round($costValue / ($totalQuantity / 1000)) : $this->scaled($product->cost_price, 2);
            if ($cost > self::MAX_SCALED) {
                $this->reject('Stock valuation needs review before this quantity can be corrected.');
            }
            foreach ($stockChanges as &$change) {
                if ($change['product_id'] === $productId) {
                    $change['cost_price_before'] = $product->cost_price;
                    $change['cost_price_after'] = $this->decimal($cost, 2);
                }
            }
            unset($change);
            $product->update(['quantity_on_hand' => $this->decimal($totalQuantity, 3), 'cost_price' => $this->decimal($cost, 2)]);
            $stock->update(['quantity_on_hand' => $this->decimal($branchQuantity, 3)]);
        }
    }

    private function scaled(string|int|float $value, int $places): int
    {
        return (int) round((float) $value * (10 ** $places));
    }

    private function decimal(int $value, int $places): string
    {
        return number_format($value / (10 ** $places), $places, '.', '');
    }

    private function reject(string $message): never
    {
        throw ValidationException::withMessages(['correction' => $message]);
    }
}
