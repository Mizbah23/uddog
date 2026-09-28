<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentItem extends Model
{
    protected $fillable = ['product_id', 'quantity', 'unit_price', 'cost_price', 'line_total'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3', 'unit_price' => 'decimal:2', 'cost_price' => 'decimal:2', 'line_total' => 'decimal:2'];
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
