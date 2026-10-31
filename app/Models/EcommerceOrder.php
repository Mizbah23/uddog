<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;

class EcommerceOrder extends TenantModel
{
    use HasFactory;

    protected $fillable = ['organization_id', 'branch_id', 'document_id', 'checkout_token', 'number', 'status', 'customer_name', 'phone', 'address', 'notes', 'items', 'total', 'processed_at', 'processed_by'];

    protected function casts(): array
    {
        return ['items' => 'array', 'total' => 'decimal:2', 'processed_at' => 'datetime'];
    }
}
