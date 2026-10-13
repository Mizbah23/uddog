<?php

namespace App\Models;

class Contact extends TenantModel
{
    protected $fillable = ['organization_id', 'name', 'company', 'type', 'phone', 'email', 'address', 'area', 'category', 'credit_limit', 'opening_due', 'opening_payable', 'active'];

    protected function casts(): array
    {
        return ['credit_limit' => 'decimal:2', 'opening_due' => 'decimal:2', 'opening_payable' => 'decimal:2', 'active' => 'boolean'];
    }
}
