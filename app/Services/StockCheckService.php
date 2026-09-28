<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductStock;
use App\Models\StockCheck;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StockCheckService
{
    public function complete(StockCheck $stockCheck, int $userId): StockCheck
    {
        return DB::transaction(function () use ($stockCheck, $userId) {
            $lockedCheck = StockCheck::query()->whereKey($stockCheck->id)->lockForUpdate()->firstOrFail();
            if ($lockedCheck->status !== 'draft') {
                throw ValidationException::withMessages(['status' => 'This stock check has already been completed.']);
            }
            $items = $lockedCheck->items()->with('product')->get();
            if ($items->contains(fn ($item) => $item->counted_quantity === null)) {
                throw ValidationException::withMessages(['items' => 'Enter a counted quantity for every product before completing the stock check.']);
            }

            foreach ($items as $item) {
                $product = Product::query()->where('organization_id', $lockedCheck->organization_id)->lockForUpdate()->findOrFail($item->product_id);
                $stock = ProductStock::query()->firstOrCreate([
                    'organization_id' => $lockedCheck->organization_id,
                    'branch_id' => $lockedCheck->branch_id,
                    'product_id' => $product->id,
                ], ['quantity_on_hand' => 0]);
                $stock = ProductStock::query()->whereKey($stock->id)->lockForUpdate()->firstOrFail();
                $current = (float) $stock->quantity_on_hand;
                $counted = (float) $item->counted_quantity;
                $difference = $counted - $current;
                if (abs($difference) < 0.0005) {
                    continue;
                }
                $aggregate = (float) $product->quantity_on_hand + $difference;
                $stock->update(['quantity_on_hand' => number_format($counted, 3, '.', '')]);
                $product->update(['quantity_on_hand' => number_format($aggregate, 3, '.', '')]);
                StockMovement::create([
                    'organization_id' => $lockedCheck->organization_id,
                    'branch_id' => $lockedCheck->branch_id,
                    'product_id' => $product->id,
                    'stock_check_id' => $lockedCheck->id,
                    'type' => 'stock_check',
                    'quantity_change' => number_format($difference, 3, '.', ''),
                    'balance_after' => number_format($counted, 3, '.', ''),
                    'notes' => "Physical count reconciliation {$lockedCheck->number}",
                    'created_by' => $userId,
                ]);
            }

            $lockedCheck->update([
                'status' => 'completed',
                'completed_by' => $userId,
                'completed_at' => now(),
            ]);

            return $lockedCheck->fresh()->load('branch', 'items.product', 'creator:id,name', 'completer:id,name');
        });
    }
}
