<?php

namespace App\Models;

class ExpenseCategory extends TenantModel
{
    protected $fillable = ['organization_id', 'name'];
}
