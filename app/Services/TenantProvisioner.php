<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Organization;
use App\Models\User;
use App\TenantContext;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class TenantProvisioner
{
    /** @var list<string> */
    private const ORGANIZATION_TABLES = [
        'branches', 'categories', 'contacts', 'products', 'product_stocks',
        'documents', 'expenses', 'gold_jobs', 'gold_rates',
        'purchase_installments', 'purchase_payments', 'sale_installments',
        'sale_payments', 'sales_targets', 'stock_checks', 'stock_movements',
        'stock_transfers', 'cash_movements', 'supplier_advances', 'supplier_advance_deliveries', 'supplier_tt_entries',
    ];

    /** @var array<string, array{0: string, 1: string}> */
    private const CHILD_TABLES = [
        'document_items' => ['document_id', 'documents'],
        'stock_check_items' => ['stock_check_id', 'stock_checks'],
        'stock_transfer_items' => ['stock_transfer_id', 'stock_transfers'],
        'branch_user_access' => ['branch_id', 'branches'],
    ];

    public function provision(Organization $organization): void
    {
        $database = $this->expectedDatabase($organization);

        $central = DB::connection(config('tenancy.central_connection'));
        if (! in_array($central->getDriverName(), ['mysql', 'mariadb'], true)) {
            throw new RuntimeException('Automatic company provisioning requires MySQL or MariaDB.');
        }
        if ($central->table('information_schema.SCHEMATA')->where('SCHEMA_NAME', $database)->exists()) {
            throw new RuntimeException('A database with this company name already exists. It was not overwritten.');
        }

        $central->statement("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $this->initializeDatabase($organization, $database, $central);
    }

    public function provisionExisting(Organization $organization): void
    {
        abort_unless(config('tenancy.provisioning_mode') === 'cpanel', 404);

        $lock = @fopen(storage_path('framework/cache/company-provision-'.$organization->id.'.lock'), 'c');
        if ($lock === false) {
            throw new RuntimeException('The company setup lock file is not writable.');
        }

        try {
            if (! flock($lock, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('Company setup is already running.');
            }

            $organization->refresh();
            $database = $this->expectedDatabase($organization);
            $central = DB::connection(config('tenancy.central_connection'));
            if (! in_array($central->getDriverName(), ['mysql', 'mariadb'], true)) {
                throw new RuntimeException('cPanel company provisioning requires MySQL or MariaDB.');
            }
            if (! $central->table('information_schema.SCHEMATA')->where('SCHEMA_NAME', $database)->exists()) {
                throw new RuntimeException("Create {$database} in cPanel and grant the application database user access before initializing this company.");
            }
            if ($central->table('information_schema.TABLES')->where('TABLE_SCHEMA', $database)->exists()) {
                throw new RuntimeException('The company database is not empty. No tables were changed; inspect it before retrying.');
            }

            $this->initializeDatabase($organization, $database, $central);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function expectedDatabase(Organization $organization): string
    {
        if (! config('tenancy.enabled')) {
            throw new RuntimeException('Enable database tenancy before provisioning companies.');
        }
        if ($organization->tenant_database) {
            throw new RuntimeException('This company already has a database.');
        }

        $database = (string) config('tenancy.database_prefix').$organization->id;
        if (! preg_match('/\A[a-zA-Z0-9_]+\z/D', $database)) {
            throw new RuntimeException('The configured company database prefix is invalid.');
        }
        if ($database === config('database.connections.'.config('tenancy.central_connection').'.database')) {
            throw new RuntimeException('The company database must not be the central database.');
        }

        return $database;
    }

    private function initializeDatabase(Organization $organization, string $database, Connection $central): void
    {
        $organization->tenant_database = $database;

        app(TenantContext::class)->run($organization, function () use ($organization, $database, $central): void {
            $exitCode = Artisan::call('migrate', ['--database' => 'tenant', '--force' => true, '--no-interaction' => true]);
            if ($exitCode !== 0) {
                Log::error('Company database migrations failed', ['organization_id' => $organization->id, 'output' => Artisan::output()]);
                throw new RuntimeException('Company database migrations failed. Review the Laravel log before retrying.');
            }

            $tenant = DB::connection('tenant');
            $reference = (array) $central->table('organizations')->where('id', $organization->id)->first();
            $reference['tenant_database'] = $database;
            $tenant->table('organizations')->insert($reference);

            $tenant->statement('SET FOREIGN_KEY_CHECKS=0');
            try {
                app(DefaultCustomer::class)->ensure($central, $organization);
                foreach (self::ORGANIZATION_TABLES as $table) {
                    $this->copyTable($table, $central->table($table)->where('organization_id', $organization->id));
                }
                foreach (self::CHILD_TABLES as $table => [$key, $parent]) {
                    $this->copyTable($table, $central->table($table)->whereIn($key, function ($query) use ($organization, $parent): void {
                        $query->select('id')->from($parent)->where('organization_id', $organization->id);
                    }));
                }

                $this->copyUserReferences($organization);
            } finally {
                $tenant->statement('SET FOREIGN_KEY_CHECKS=1');
            }

            foreach (self::ORGANIZATION_TABLES as $table) {
                $expected = $central->table($table)->where('organization_id', $organization->id)->count();
                if ($tenant->table($table)->where('organization_id', $organization->id)->count() !== $expected) {
                    throw new RuntimeException("Company data verification failed for {$table}.");
                }
            }
            foreach (self::CHILD_TABLES as $table => [$key, $parent]) {
                $expected = $central->table($table)->whereIn($key, function ($query) use ($organization, $parent): void {
                    $query->select('id')->from($parent)->where('organization_id', $organization->id);
                })->count();
                if ($tenant->table($table)->count() !== $expected) {
                    throw new RuntimeException("Company data verification failed for {$table}.");
                }
            }

            if (! $tenant->table('branches')->where('organization_id', $organization->id)->exists()) {
                Branch::create(['organization_id' => $organization->id, 'name' => 'Main Branch', 'code' => 'MAIN', 'is_default' => true, 'active' => true]);
            }
        });

        $organization->save();
    }

    public function syncUserShadow(User $user): void
    {
        $organization = $user->organization;
        if (! config('tenancy.enabled') || ! $organization?->tenant_database) {
            return;
        }

        app(TenantContext::class)->run($organization, function () use ($user, $organization): void {
            DB::connection('tenant')->table('users')->updateOrInsert(
                ['id' => $user->id],
                [
                    'organization_id' => $user->organization_id === $organization->id ? $organization->id : null,
                    'name' => $user->name,
                    'email' => $user->organization_id === $organization->id
                        ? $user->email : 'shadow-'.$user->id.'@tenant.invalid',
                    'password' => Hash::make(Str::random(40)),
                    'role' => $user->role->value,
                    'active' => $user->active,
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
        });
    }

    public function syncOrganizationShadow(Organization $organization): void
    {
        if (! config('tenancy.enabled') || ! $organization->tenant_database) {
            return;
        }

        app(TenantContext::class)->run($organization, function () use ($organization): void {
            $row = DB::connection(config('tenancy.central_connection'))
                ->table('organizations')->where('id', $organization->id)->first();
            DB::connection('tenant')->table('organizations')->where('id', $organization->id)->update((array) $row);
        });
    }

    /** @return list<string> */
    public function verifyCopiedData(Organization $organization): array
    {
        if (! $organization->tenant_database) {
            return ['Company database is not provisioned.'];
        }

        return app(TenantContext::class)->run($organization, function () use ($organization): array {
            $central = DB::connection(config('tenancy.central_connection'));
            $tenant = DB::connection('tenant');
            $differences = [];

            foreach (self::ORGANIZATION_TABLES as $table) {
                $source = $central->table($table)->where('organization_id', $organization->id);
                $copy = $tenant->table($table)->where('organization_id', $organization->id);
                if ($this->tableFingerprint($source) !== $this->tableFingerprint($copy)) {
                    $differences[] = $table;
                }
            }
            foreach (self::CHILD_TABLES as $table => [$key, $parent]) {
                $parentIds = $central->table($parent)->where('organization_id', $organization->id)->pluck('id');
                $source = $central->table($table)->whereIn($key, $parentIds);
                $copy = $tenant->table($table)->whereIn($key, $parentIds);
                if ($this->tableFingerprint($source) !== $this->tableFingerprint($copy)) {
                    $differences[] = $table;
                }
            }

            return $differences;
        });
    }

    private function tableFingerprint(Builder $query): string
    {
        $hash = hash_init('sha256');
        $query->orderBy('id')->chunkById(500, function ($rows) use ($hash): void {
            foreach ($rows as $row) {
                hash_update($hash, json_encode((array) $row, JSON_THROW_ON_ERROR));
            }
        });

        return hash_final($hash);
    }

    private function copyTable(string $table, Builder $source): void
    {
        $source->orderBy('id')->chunkById(500, function ($rows) use ($table): void {
            DB::connection('tenant')->table($table)->insert($rows->map(fn ($row): array => (array) $row)->all());
        });
    }

    private function copyUserReferences(Organization $organization): void
    {
        $tenant = DB::connection('tenant');
        $ids = DB::connection(config('tenancy.central_connection'))->table('users')
            ->where('organization_id', $organization->id)->pluck('id');

        foreach (['documents' => ['created_by'], 'expenses' => ['created_by'], 'gold_jobs' => ['created_by'], 'gold_rates' => ['created_by'], 'purchase_payments' => ['created_by'], 'sale_payments' => ['created_by'], 'sales_targets' => ['created_by', 'user_id'], 'stock_checks' => ['created_by', 'completed_by'], 'stock_movements' => ['created_by'], 'stock_transfers' => ['created_by'], 'cash_movements' => ['from_user_id', 'to_user_id'], 'branch_user_access' => ['user_id']] as $table => $columns) {
            foreach ($columns as $column) {
                $ids = $ids->concat($tenant->table($table)->whereNotNull($column)->pluck($column));
            }
        }

        User::query()->whereIn('id', $ids->unique()->values()->all())->get()->each(function (User $user) use ($organization, $tenant): void {
            $tenant->table('users')->insert([
                'id' => $user->id,
                'organization_id' => $user->organization_id === $organization->id ? $organization->id : null,
                'name' => $user->name,
                'email' => $user->organization_id === $organization->id
                    ? $user->email : 'shadow-'.$user->id.'@tenant.invalid',
                'password' => Hash::make(Str::random(40)),
                'role' => $user->role->value,
                'active' => $user->active,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }
}
