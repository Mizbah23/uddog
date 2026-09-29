<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Contact extends Model
{
    protected $fillable = ['organization_id', 'name', 'company', 'type', 'phone', 'email', 'address', 'area', 'category', 'credit_limit', 'active'];

    protected function casts(): array
    {
        return ['credit_limit' => 'decimal:2', 'active' => 'boolean'];
    }
}
