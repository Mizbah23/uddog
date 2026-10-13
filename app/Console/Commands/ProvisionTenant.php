<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Services\TenantProvisioner;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('tenants:provision {company : The company ID in the central database}')]
#[Description('Create and verify a separate database for an existing company without deleting central data')]
class ProvisionTenant extends Command
{
    public function handle(TenantProvisioner $provisioner): int
    {
        $organization = Organization::query()->findOrFail((int) $this->argument('company'));
        $provisioner->provision($organization);
        $this->components->info("Company {$organization->id} is ready in {$organization->tenant_database}.");

        return self::SUCCESS;
    }
}
