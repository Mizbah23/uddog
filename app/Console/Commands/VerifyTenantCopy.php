<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Services\TenantProvisioner;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('tenants:verify-copy {company : The company ID in the central database}')]
#[Description('Compare the original central company rows with its copied database before cutover')]
class VerifyTenantCopy extends Command
{
    public function handle(TenantProvisioner $provisioner): int
    {
        if (! config('tenancy.enabled')) {
            $this->components->error('Enable database tenancy for this check. It does not change data.');

            return self::FAILURE;
        }

        $company = Organization::query()->findOrFail((int) $this->argument('company'));
        $differences = $provisioner->verifyCopiedData($company);
        if ($differences !== []) {
            $this->components->error('Company data differs in: '.implode(', ', $differences));

            return self::FAILURE;
        }

        $this->components->info("Company {$company->id} business rows match the central source.");

        return self::SUCCESS;
    }
}
