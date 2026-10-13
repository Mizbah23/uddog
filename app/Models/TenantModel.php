<?php

namespace App\Models;

use App\TenantContext;
use Illuminate\Database\Eloquent\Model;

abstract class TenantModel extends Model
{
    public function getConnectionName(): ?string
    {
        return config('tenancy.enabled') ? app(TenantContext::class)->connectionName() : parent::getConnectionName();
    }
}
