<?php

namespace App\Models;

class SupplierAdvanceDelivery extends TenantModel
{
    protected $fillable = ['organization_id', 'branch_id', 'supplier_advance_id', 'company_name', 'installment_code', 'product_details', 'amount', 'delivery_date', 'notes', 'created_by'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'delivery_date' => 'date:Y-m-d'];
    }

    public function advance()
    {
        return $this->belongsTo(SupplierAdvance::class, 'supplier_advance_id');
    }
}
