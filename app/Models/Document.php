<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Document extends Model
{
    protected $fillable = ['organization_id', 'branch_id', 'number', 'type', 'sale_channel', 'contact_id', 'purchase_id', 'sale_id', 'document_date', 'subtotal', 'discount', 'tax', 'total', 'payment_method', 'amount_paid', 'notes', 'created_by'];

    protected $appends = ['stock_profit', 'total_profit', 'balance_due'];

    protected function casts(): array
    {
        return ['subtotal' => 'decimal:2', 'discount' => 'decimal:2', 'tax' => 'decimal:2', 'total' => 'decimal:2', 'amount_paid' => 'decimal:2'];
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

    public function purchase()
    {
        return $this->belongsTo(self::class, 'purchase_id');
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
        return number_format(max(0, (float) $this->total - (float) $this->amount_paid), 2, '.', '');
    }
}
