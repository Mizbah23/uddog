<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockMovement extends Model
{
    protected $fillable = ['organization_id', 'branch_id', 'product_id', 'document_id', 'stock_check_id', 'stock_transfer_id', 'type', 'quantity_change', 'balance_after', 'notes', 'created_by'];

    protected function casts(): array
    {
        return ['quantity_change' => 'decimal:3', 'balance_after' => 'decimal:3'];
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function document()
    {
        return $this->belongsTo(Document::class);
    }

    public function stockCheck()
    {
        return $this->belongsTo(StockCheck::class);
    }

    public function stockTransfer()
    {
        return $this->belongsTo(StockTransfer::class);
    }
}
