<?php

namespace App\Models;

class GoldRate extends TenantModel
{
    protected $fillable = ['organization_id', 'branch_id', 'metal', 'purity', 'rate_per_gram', 'effective_on', 'created_by'];

    protected function casts(): array
    {
        return ['rate_per_gram' => 'decimal:2', 'effective_on' => 'date:Y-m-d'];
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }
}
