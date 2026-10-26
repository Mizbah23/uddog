<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class SupplierAdvance extends TenantModel
{
    protected $fillable = ['organization_id', 'branch_id', 'contact_id', 'amount', 'expected_installments', 'advance_date', 'payment_method', 'reference', 'notes', 'created_by'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'expected_installments' => 'integer', 'advance_date' => 'date:Y-m-d'];
    }

    public function supplier()
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(SupplierAdvanceDelivery::class)->orderBy('delivery_date')->orderBy('id');
    }
}
