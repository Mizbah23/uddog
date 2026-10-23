<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends TenantModel
{
    protected $fillable = ['organization_id', 'sku', 'barcode', 'category_id', 'name', 'unit', 'cost_price', 'sale_price', 'quantity_on_hand', 'reorder_level', 'warranty_months', 'active', 'is_gold_item', 'gold_purity', 'gold_gross_weight', 'gold_stone_weight', 'gold_other_weight', 'stone_value', 'making_charge_mode', 'making_charge', 'wastage_percent', 'tag_number', 'hallmark_code', 'stock_source_product_id', 'stock_source_quantity'];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'cost_price' => 'decimal:2',
            'sale_price' => 'decimal:2',
            'quantity_on_hand' => 'decimal:3',
            'reorder_level' => 'decimal:3',
            'warranty_months' => 'integer',
            'is_gold_item' => 'boolean',
            'gold_gross_weight' => 'decimal:3',
            'gold_stone_weight' => 'decimal:3',
            'gold_other_weight' => 'decimal:3',
            'stone_value' => 'decimal:2',
            'making_charge' => 'decimal:2',
            'wastage_percent' => 'decimal:3',
            'stock_source_quantity' => 'decimal:3',
        ];
    }

    protected static function booted(): void
    {
        static::created(function (Product $product) {
            $branchId = Branch::query()->where('organization_id', $product->organization_id)->where('is_default', true)->value('id');
            if ($branchId) {
                $product->stocks()->create([
                    'organization_id' => $product->organization_id,
                    'branch_id' => $branchId,
                    'quantity_on_hand' => $product->quantity_on_hand ?? 0,
                ]);
            }
        });
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function stocks(): HasMany
    {
        return $this->hasMany(ProductStock::class);
    }

    public function stockSource(): BelongsTo
    {
        return $this->belongsTo(self::class, 'stock_source_product_id');
    }

    public function salesProducts(): HasMany
    {
        return $this->hasMany(self::class, 'stock_source_product_id');
    }
}
