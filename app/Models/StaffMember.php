<?php

namespace App\Models;

use Database\Factories\StaffMemberFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class StaffMember extends TenantModel
{
    /** @use HasFactory<StaffMemberFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = ['organization_id', 'branch_id', 'name', 'job_title', 'phone', 'email', 'monthly_salary', 'start_date', 'active'];

    protected function casts(): array
    {
        return ['monthly_salary' => 'decimal:2', 'start_date' => 'date:Y-m-d', 'active' => 'boolean'];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
