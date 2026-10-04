<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseInstallment extends Model
{
    protected $fillable = ['organization_id', 'branch_id', 'document_id', 'sequence', 'due_date', 'amount', 'amount_paid'];

    protected $appends = ['balance_due', 'status'];

    protected function casts(): array
    {
        return ['due_date' => 'date:Y-m-d', 'amount' => 'decimal:2', 'amount_paid' => 'decimal:2'];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(PurchasePayment::class);
    }

    public function getBalanceDueAttribute(): string
    {
        return number_format(max(0, (float) $this->amount - (float) $this->amount_paid), 2, '.', '');
    }

    public function getStatusAttribute(): string
    {
        return (float) $this->balance_due === 0.0 ? 'paid' : ((float) $this->amount_paid > 0 ? 'partial' : 'due');
    }
}
