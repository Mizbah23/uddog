<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollItem extends TenantModel
{
    protected $fillable = ['payroll_run_id', 'staff_member_id', 'expense_id', 'staff_name', 'job_title', 'amount'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }
}
