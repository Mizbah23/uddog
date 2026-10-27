<?php

namespace App\Services;

use App\Models\Organization;
use App\TenantContext;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
            if ($pending['central'] > 0) {
                $exitCode = Artisan::call('migrate', [
                    '--database' => (string) config('tenancy.central_connection'),
                    '--force' => true,
                    '--no-interaction' => true,
                ]);
                if ($exitCode !== 0) {
                    throw $this->migrationFailure('Central', $exitCode);
                }
            }

            if ($pending['tenants'] > 0) {
                $exitCode = Artisan::call('tenants:migrate', [
                    '--no-interaction' => true,
                ]);
                if ($exitCode !== 0) {
                    throw $this->migrationFailure('Company', $exitCode);
                }
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

    private function migrationFailure(string $phase, int $exitCode): RuntimeException
    {
        $output = trim(Artisan::output());
        Log::error("{$phase} database migration failed.", ['exit_code' => $exitCode, 'artisan_output' => $output]);
        preg_match('/SQLSTATE\[[^]]+\]:\s*[^\r\n(]+/', $output, $match);
        $reason = isset($match[0]) ? ' '.mb_strimwidth(trim($match[0]), 0, 240, '…') : '';

        return new RuntimeException("{$phase} database migration failed.{$reason}");
    }
}
