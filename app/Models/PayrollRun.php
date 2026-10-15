<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayrollRun extends TenantModel
{
    protected $fillable = ['organization_id', 'branch_id', 'period', 'payment_date', 'payment_method', 'staff_count', 'total_amount', 'created_by'];

    protected function casts(): array
    {
        return ['payment_date' => 'date:Y-m-d', 'total_amount' => 'decimal:2'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(PayrollItem::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
