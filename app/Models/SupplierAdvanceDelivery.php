<?php

namespace App\Models;

class SupplierAdvanceDelivery extends TenantModel
{
    protected $fillable = ['organization_id', 'branch_id', 'supplier_advance_id', 'document_id', 'purchase_payment_id', 'installment_code', 'amount', 'delivery_date', 'notes', 'created_by'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'delivery_date' => 'date:Y-m-d'];
    }

    public function purchase()
    {
        return $this->belongsTo(Document::class, 'document_id');
    }

    public function advance()
    {
        return $this->belongsTo(SupplierAdvance::class, 'supplier_advance_id');
    }
}
