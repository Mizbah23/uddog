<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\TenantContext;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

#[Signature('tenants:migrate {--company= : Migrate only this company ID} {--pretend : Display pending SQL without applying it}')]
#[Description('Apply pending Laravel migrations to every provisioned company database')]
class MigrateTenants extends Command
{
    public function handle(TenantContext $context): int
    {
        if (! config('tenancy.enabled')) {
            $this->components->error('Enable database tenancy before migrating company databases.');

            return self::FAILURE;
        }

        $query = Organization::query()->whereNotNull('tenant_database')->orderBy('id');
        if ($this->option('company')) {
            $query->whereKey((int) $this->option('company'));
        }

        $companies = $query->get();
        if ($companies->isEmpty()) {
            $this->components->warn('No provisioned companies matched.');

            return self::SUCCESS;
        }

        foreach ($companies as $company) {
            $this->components->info("Migrating company {$company->id} ({$company->tenant_database})");
            $result = $context->run($company, fn () => Artisan::call('migrate', [
                '--database' => 'tenant',
                '--force' => true,
                '--no-interaction' => true,
                '--pretend' => (bool) $this->option('pretend'),
            ]));
            $this->output->write(Artisan::output());
            if ($result !== 0) {
                $this->components->error("Stopped at company {$company->id}. Other company databases have not been migrated.");

                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }
}
