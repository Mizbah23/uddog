<?php

namespace App\Models;

class SupplierTtEntry extends TenantModel
{
    protected $fillable = ['organization_id', 'branch_id', 'company_name', 'entry_type', 'particular', 'amount', 'entry_date', 'notes', 'created_by'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'entry_date' => 'date:Y-m-d'];
    }
}
