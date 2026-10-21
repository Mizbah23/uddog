<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentItem extends TenantModel
{
    protected $fillable = ['product_id', 'quantity', 'unit_price', 'cost_price', 'entered_unit', 'unit_conversion', 'entered_quantity', 'entered_unit_price', 'warranty_months', 'line_total', 'gold_gross_weight', 'gold_net_weight', 'gold_purity', 'gold_rate', 'making_charge', 'wastage_weight', 'stone_value', 'tag_number', 'hallmark_code'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3', 'unit_price' => 'decimal:2', 'cost_price' => 'decimal:2', 'entered_quantity' => 'decimal:3', 'entered_unit_price' => 'decimal:2', 'unit_conversion' => 'integer', 'warranty_months' => 'integer', 'line_total' => 'decimal:2', 'gold_gross_weight' => 'decimal:3', 'gold_net_weight' => 'decimal:3', 'gold_rate' => 'decimal:2', 'making_charge' => 'decimal:2', 'wastage_weight' => 'decimal:3', 'stone_value' => 'decimal:2'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
