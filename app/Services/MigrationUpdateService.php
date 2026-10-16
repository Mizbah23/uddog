<?php

namespace App\Services;

use App\Models\Organization;
use App\TenantContext;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class MigrationUpdateService
{
    /**
     * @return array{central: int, tenants: int, companies: int, total: int}
     */
    public function status(Migrator $migrator, TenantContext $context): array
    {
        $migrationNames = array_keys($migrator->getMigrationFiles([
            database_path('migrations'),
            ...$migrator->paths(),
        ]));
        $centralConnection = (string) config('tenancy.central_connection');
        $central = $this->pendingCount($centralConnection, $migrationNames);
        $tenants = 0;
        $companies = 0;

        if (config('tenancy.enabled')) {
            Organization::query()->whereNotNull('tenant_database')->orderBy('id')->get()
                ->each(function (Organization $organization) use ($context, $migrationNames, &$tenants, &$companies): void {
                    $pending = $context->run($organization, fn (): int => $this->pendingCount('tenant', $migrationNames));
                    $tenants += $pending;
                    $companies += (int) ($pending > 0);
                });
        }

        return [
            'central' => $central,
            'tenants' => $tenants,
            'companies' => $companies,
            'total' => $central + $tenants,
        ];
    }

    /**
     * @return array{central: int, tenants: int, companies: int, total: int}
     */
    public function apply(Migrator $migrator, TenantContext $context): array
    {
        $lock = @fopen(storage_path('framework/cache/system-update.lock'), 'c');
        if ($lock === false) {
            throw new RuntimeException('The migration lock file is not writable.');
        }

        try {
            if (! flock($lock, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('A system update is already running.');
            }

            $pending = $this->status($migrator, $context);
            if ($pending['central'] > 0 && Artisan::call('migrate', [
                '--database' => (string) config('tenancy.central_connection'),
                '--force' => true,
                '--no-interaction' => true,
            ]) !== 0) {
                throw new RuntimeException('Central database migration failed.');
            }

            if ($pending['tenants'] > 0 && Artisan::call('tenants:migrate', [
                '--no-interaction' => true,
            ]) !== 0) {
                throw new RuntimeException('Company database migration failed.');
            }

            return $this->status($migrator, $context);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * @param  list<string>  $migrationNames
     */
    private function pendingCount(string $connectionName, array $migrationNames): int
    {
        $connection = DB::connection($connectionName);
        if (! $connection->getSchemaBuilder()->hasTable('migrations')) {
            throw new RuntimeException("The {$connectionName} migration history is missing. Use the deployment procedure before updating from the app.");
        }

        $completed = $connection->table('migrations')->pluck('migration')->all();

        return count(array_diff($migrationNames, $completed));
    }
}
