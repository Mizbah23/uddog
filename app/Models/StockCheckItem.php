<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockCheckItem extends Model
{
    protected $fillable = ['product_id', 'expected_quantity', 'counted_quantity'];

    protected $appends = ['variance'];

    protected function casts(): array
    {
        return ['expected_quantity' => 'decimal:3', 'counted_quantity' => 'decimal:3'];
    }

    public function stockCheck(): BelongsTo
    {
        return $this->belongsTo(StockCheck::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function getVarianceAttribute(): ?string
    {
        if ($this->counted_quantity === null) {
            return null;
        }

        return number_format((float) $this->counted_quantity - (float) $this->expected_quantity, 3, '.', '');
    }
}
