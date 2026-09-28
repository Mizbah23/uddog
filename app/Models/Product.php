<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    protected $fillable = ['organization_id', 'sku', 'barcode', 'category_id', 'name', 'unit', 'cost_price', 'sale_price', 'quantity_on_hand', 'reorder_level', 'warranty_months', 'active'];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'cost_price' => 'decimal:2',
            'sale_price' => 'decimal:2',
            'quantity_on_hand' => 'decimal:3',
            'reorder_level' => 'decimal:3',
            'warranty_months' => 'integer',
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
}
