<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class Document extends TenantModel
{
    protected $fillable = ['organization_id', 'branch_id', 'number', 'type', 'sale_channel', 'contact_id', 'purchase_id', 'sale_id', 'document_date', 'subtotal', 'discount', 'tax', 'interest_rate', 'interest_amount', 'gold_exchange_weight', 'gold_exchange_purity', 'gold_exchange_rate', 'gold_exchange_value', 'total', 'payment_method', 'payment_type', 'amount_paid', 'down_payment', 'installment_count', 'installment_frequency', 'installment_start_date', 'first_installment_date', 'notes', 'created_by'];

    protected $appends = ['stock_profit', 'total_profit', 'balance_due', 'payment_status', 'correction_version'];

    protected function casts(): array
    {
        return ['document_date' => 'date:Y-m-d', 'installment_start_date' => 'date:Y-m-d', 'first_installment_date' => 'date:Y-m-d', 'subtotal' => 'decimal:2', 'discount' => 'decimal:2', 'tax' => 'decimal:2', 'interest_rate' => 'decimal:2', 'interest_amount' => 'decimal:2', 'gold_exchange_weight' => 'decimal:3', 'gold_exchange_rate' => 'decimal:2', 'gold_exchange_value' => 'decimal:2', 'total' => 'decimal:2', 'amount_paid' => 'decimal:2', 'down_payment' => 'decimal:2'];
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

    public function corrections(): HasMany
    {
        return $this->hasMany(SaleCorrection::class)->orderByDesc('id');
    }

    public function correctionSnapshot(): array
    {
        return [
            ...$this->only(['subtotal', 'discount', 'tax', 'total', 'amount_paid', 'payment_type', 'interest_amount', 'gold_exchange_value']),
            'items' => $this->items->sortBy('id')->values()->map(fn (DocumentItem $item): array => $item->only([
                'id', 'product_id', 'stock_product_id', 'quantity', 'stock_quantity', 'unit_price',
                'entered_unit', 'unit_conversion', 'entered_quantity', 'entered_unit_price', 'cost_price', 'line_total',
            ]))->all(),
        ];
    }

    public function getCorrectionVersionAttribute(): string
    {
        return hash('sha256', json_encode($this->correctionSnapshot(), JSON_THROW_ON_ERROR));
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
        $profit = $items->sum(fn (DocumentItem $item) => (float) $item->line_total - ((float) $item->cost_price * (float) $item->quantity));

        return number_format($profit, 2, '.', '');
    }

    public function getTotalProfitAttribute(): string
    {
        if (! in_array($this->type, ['sale', 'resale'], true)) {
            return '0.00';
        }

        return number_format((float) $this->stock_profit - (float) $this->discount + (float) $this->interest_amount, 2, '.', '');
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
