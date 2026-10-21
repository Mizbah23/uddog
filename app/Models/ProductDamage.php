<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductDamage extends TenantModel
{
    protected $fillable = ['organization_id', 'branch_id', 'purchase_id', 'product_id', 'quantity', 'damaged_on', 'reason', 'notes', 'created_by'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3', 'damaged_on' => 'date:Y-m-d'];
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'purchase_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
