<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaleCorrection extends TenantModel
{
    protected $fillable = ['organization_id', 'branch_id', 'document_id', 'created_by', 'reason', 'before', 'after', 'stock_changes'];

    protected function casts(): array
    {
        return ['before' => 'array', 'after' => 'array', 'stock_changes' => 'array'];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
