<?php

namespace App\Models;

class GoldJob extends TenantModel
{
    protected $fillable = ['organization_id', 'branch_id', 'number', 'job_type', 'status', 'contact_id', 'product_id', 'document_id', 'gold_purity', 'input_weight', 'output_weight', 'wastage_weight', 'labor_amount', 'due_date', 'notes', 'created_by'];

    protected function casts(): array
    {
        return ['input_weight' => 'decimal:3', 'output_weight' => 'decimal:3', 'wastage_weight' => 'decimal:3', 'labor_amount' => 'decimal:2', 'due_date' => 'date:Y-m-d'];
    }

    public function contact()
    {
        return $this->belongsTo(Contact::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
