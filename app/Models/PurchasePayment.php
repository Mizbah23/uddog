<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchasePayment extends Model
{
    protected $fillable = ['organization_id', 'branch_id', 'document_id', 'purchase_installment_id', 'amount', 'payment_method', 'payment_date', 'notes', 'created_by'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'payment_date' => 'date:Y-m-d'];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function installment(): BelongsTo
    {
        return $this->belongsTo(PurchaseInstallment::class, 'purchase_installment_id');
    }
}
