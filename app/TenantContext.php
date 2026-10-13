<?php

namespace App;

use App\Models\Organization;
use Closure;
use Illuminate\Support\Facades\DB;
use LogicException;

class TenantContext
{
    private ?Organization $organization = null;

    public function organization(): ?Organization
    {
        return $this->organization;
    }

    public function connectionName(): string
    {
        if (! config('tenancy.enabled')) {
            return config('database.default');
        }

        if (! $this->organization) {
            throw new LogicException('A company database must be selected before accessing company data.');
        }

        return 'tenant';
    }

    public function validationTable(string $table): string
    {
        return config('tenancy.enabled') ? $this->connectionName().'.'.$table : $table;
    }

    public function activate(Organization $organization): void
    {
        if (! config('tenancy.enabled')) {
            return;
        }

        $database = $organization->tenant_database;
        if (! is_string($database) || ! preg_match('/\A[a-zA-Z0-9_]+\z/D', $database)) {
            throw new LogicException('The company database is not provisioned.');
        }

        $source = config('database.connections.'.config('tenancy.central_connection'));
        if (! is_array($source)) {
            throw new LogicException('The central database connection is missing.');
        }

        $selectedDatabase = $source['driver'] === 'sqlite'
            ? rtrim((string) config('tenancy.sqlite_directory'), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$database.'.sqlite'
            : $database;
        config(['database.connections.tenant' => [...$source, 'url' => null, 'database' => $selectedDatabase]]);
        DB::purge('tenant');
        DB::connection('tenant')->getPdo();
        $this->organization = $organization;
    }

    public function clear(): void
    {
        $this->organization = null;
        DB::purge('tenant');
    }

    public function run(Organization $organization, Closure $callback): mixed
    {
        if (! config('tenancy.enabled')) {
            return $callback();
        }

        if ($this->organization?->id === $organization->id) {
            return $callback();
        }

        $previous = $this->organization;
        $this->activate($organization);

        try {
            return $callback();
        } finally {
            if ($previous) {
                $this->activate($previous);
            } else {
                $this->clear();
            }
        }
    }
}
