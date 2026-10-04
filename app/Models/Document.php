<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Document extends Model
{
    protected $fillable = ['organization_id', 'branch_id', 'number', 'type', 'sale_channel', 'contact_id', 'purchase_id', 'sale_id', 'document_date', 'subtotal', 'discount', 'tax', 'total', 'payment_method', 'payment_type', 'amount_paid', 'down_payment', 'installment_count', 'installment_frequency', 'first_installment_date', 'notes', 'created_by'];

    protected $appends = ['stock_profit', 'total_profit', 'balance_due', 'payment_status'];

    protected function casts(): array
    {
        return ['document_date' => 'date:Y-m-d', 'first_installment_date' => 'date:Y-m-d', 'subtotal' => 'decimal:2', 'discount' => 'decimal:2', 'tax' => 'decimal:2', 'total' => 'decimal:2', 'amount_paid' => 'decimal:2', 'down_payment' => 'decimal:2'];
    }

    public function contact()
    {
        return $this->belongsTo(Contact::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function items()
    {
        return $this->hasMany(DocumentItem::class);
    }

    public function installments(): HasMany
    {
        return $this->hasMany(SaleInstallment::class)->orderBy('sequence');
    }

    public function purchaseInstallments(): HasMany
    {
        return $this->hasMany(PurchaseInstallment::class)->orderBy('sequence');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SalePayment::class)->latest('payment_date')->latest('id');
    }

    public function purchasePayments(): HasMany
    {
        return $this->hasMany(PurchasePayment::class)->latest('payment_date')->latest('id');
    }

    public function purchase()
    {
        return $this->belongsTo(self::class, 'purchase_id');
    }

    public function purchaseReturns(): HasMany
    {
        return $this->hasMany(self::class, 'purchase_id')->where('type', 'purchase_return');
    }

    public function sale()
    {
        return $this->belongsTo(self::class, 'sale_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function getStockProfitAttribute(): string
    {
        if (! in_array($this->type, ['sale', 'resale'], true)) {
            return '0.00';
        }

        $items = $this->relationLoaded('items') ? $this->items : $this->items()->get();
        $profit = $items->sum(fn (DocumentItem $item) => ((float) $item->unit_price - (float) $item->cost_price) * (float) $item->quantity);

        return number_format($profit, 2, '.', '');
    }

    public function getTotalProfitAttribute(): string
    {
        if (! in_array($this->type, ['sale', 'resale'], true)) {
            return '0.00';
        }

        return number_format((float) $this->stock_profit - (float) $this->discount, 2, '.', '');
    }

    public function getBalanceDueAttribute(): string
    {
        $purchaseReturns = $this->type === 'purchase'
            ? ($this->relationLoaded('purchaseReturns') ? $this->purchaseReturns->sum('total') : $this->purchaseReturns()->sum('total'))
            : 0;

        return number_format(max(0, (float) $this->total - (float) $this->amount_paid - (float) $purchaseReturns), 2, '.', '');
    }

    public function getPaymentStatusAttribute(): string
    {
        if (! in_array($this->type, ['sale', 'resale'], true)) {
            return 'not_applicable';
        }

        if ((float) $this->balance_due === 0.0) {
            return 'paid';
        }

        return (float) $this->amount_paid > 0 ? 'partial' : 'due';
    }
}
