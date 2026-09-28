<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesTarget extends Model
{
    protected $fillable = ['organization_id', 'branch_id', 'user_id', 'period', 'target_amount', 'notes', 'created_by'];

    protected function casts(): array
    {
        return ['period' => 'date:Y-m-d', 'target_amount' => 'decimal:2'];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
