<?php

namespace App\Services;

use App\Models\Document;
use App\Models\DocumentItem;
use App\Models\Product;
use App\Models\ProductDamage;
use App\Models\ProductStock;
use App\Models\StockMovement;
use App\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductDamageService
{
    public function create(array $data, int $organizationId, int $branchId, int $userId): ProductDamage
    {
        return DB::connection(app(TenantContext::class)->connectionName())->transaction(function () use ($data, $organizationId, $branchId, $userId): ProductDamage {
            $purchase = Document::query()->where('organization_id', $organizationId)->lockForUpdate()->findOrFail($data['purchase_id']);
            if ($purchase->type !== 'purchase' || (int) $purchase->branch_id !== $branchId) {
                throw ValidationException::withMessages(['purchase_id' => 'Select a purchase from the active branch.']);
            }

            $product = Product::query()->where('organization_id', $organizationId)->lockForUpdate()->findOrFail($data['product_id']);
            $purchased = self::scaled($purchase->items()->where('product_id', $product->id)->sum('quantity'));
            if ($purchased === 0) {
                throw ValidationException::withMessages(['product_id' => 'Select a product from the chosen purchase.']);
            }
            if ($data['damaged_on'] < $purchase->document_date->toDateString()) {
                throw ValidationException::withMessages(['damaged_on' => 'Damage date cannot be before the purchase date.']);
            }

            $returned = self::scaled(DocumentItem::query()
                ->where('product_id', $product->id)
                ->whereHas('document', fn ($query) => $query->where('type', 'purchase_return')->where('purchase_id', $purchase->id))
                ->sum('quantity'));
            $alreadyDamaged = self::scaled(ProductDamage::query()
                ->where('purchase_id', $purchase->id)->where('product_id', $product->id)->sum('quantity'));
            $quantity = self::scaled($data['quantity']);
            if ($product->usesWholePieces() && $quantity % 1000 !== 0) {
                throw ValidationException::withMessages(['quantity' => 'Enter a whole-piece damaged quantity for this product.']);
            }
            if ($quantity > $purchased - $returned - $alreadyDamaged) {
                throw ValidationException::withMessages(['quantity' => 'Damage exceeds the remaining quantity from this purchase.']);
            }

            ProductStock::query()->firstOrCreate([
                'organization_id' => $organizationId,
                'branch_id' => $branchId,
                'product_id' => $product->id,
            ], ['quantity_on_hand' => 0]);
            $stock = ProductStock::query()->where('branch_id', $branchId)->where('product_id', $product->id)->lockForUpdate()->firstOrFail();
            $branchBalance = self::scaled($stock->quantity_on_hand) - $quantity;
            $totalBalance = self::scaled($product->quantity_on_hand) - $quantity;
            if ($branchBalance < 0 || $totalBalance < 0) {
                throw ValidationException::withMessages(['quantity' => 'Damage quantity exceeds stock available in this branch.']);
            }

            $damage = ProductDamage::create([
                'organization_id' => $organizationId,
                'branch_id' => $branchId,
                'purchase_id' => $purchase->id,
                'product_id' => $product->id,
                'quantity' => self::quantity($quantity),
                'damaged_on' => $data['damaged_on'],
                'reason' => $data['reason'],
                'notes' => $data['notes'] ?? null,
                'created_by' => $userId,
            ]);
            $stock->update(['quantity_on_hand' => self::quantity($branchBalance)]);
            $product->update(['quantity_on_hand' => self::quantity($totalBalance)]);
            StockMovement::create([
                'organization_id' => $organizationId,
                'branch_id' => $branchId,
                'product_id' => $product->id,
                'product_damage_id' => $damage->id,
                'type' => 'damage',
                'quantity_change' => self::quantity(-$quantity),
                'balance_after' => self::quantity($branchBalance),
                'notes' => $data['reason'],
                'created_by' => $userId,
            ]);

            return $damage->load('purchase:id,number,document_date', 'product:id,name,sku,unit', 'creator:id,name');
        });
    }

    private static function scaled(string|int|float $value): int
    {
        [$whole, $fraction] = array_pad(explode('.', (string) $value, 2), 2, '');

        return ((int) $whole * 1000) + (int) str_pad(substr($fraction, 0, 3), 3, '0');
    }

    private static function quantity(int $mills): string
    {
        return number_format($mills / 1000, 3, '.', '');
    }
}
