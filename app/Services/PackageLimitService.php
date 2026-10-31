<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Document;
use App\Models\Organization;
use App\Models\User;
use App\TenantContext;
use App\UserRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PackageLimitService
{
    public function assertUserCapacity(int $organizationId, ?int $userId = null): void
    {
        $company = Organization::query()->lockForUpdate()->findOrFail($organizationId);
        $limit = $company->packageLimits()['users'];
        if ($limit === null) {
            return;
        }
        $current = $userId ? User::query()->find($userId) : null;
        if ($current && $current->active && ! $current->isSuperadmin() && (int) $current->organization_id === $organizationId) {
            return;
        }
        $count = User::query()->where('organization_id', $organizationId)->where('active', true)->where('role', '!=', UserRole::Superadmin->value)->count();
        if ($count >= $limit) {
            throw ValidationException::withMessages(['package_limits' => "Active user limit reached ({$limit}, including the owner). Deactivate an account or ask the superadmin to increase this company's allowance."]);
        }
    }

    public function assertBranchCapacity(int $organizationId, ?int $branchId = null): void
    {
        $connection = DB::connection(app(TenantContext::class)->connectionName());
        $connection->table('organizations')->where('id', $organizationId)->lockForUpdate()->firstOrFail();
        $company = Organization::query()->findOrFail($organizationId);
        $limit = $company->packageLimits()['branches'];
        if ($limit === null || ($branchId && Branch::query()->where('organization_id', $organizationId)->whereKey($branchId)->where('active', true)->exists())) {
            return;
        }
        if (Branch::query()->where('organization_id', $organizationId)->where('active', true)->count() >= $limit) {
            throw ValidationException::withMessages(['package_limits' => "Active branch limit reached ({$limit}). Deactivate a branch or ask the superadmin to increase this company's allowance."]);
        }
    }

    public function consumeSaleInvoice(int $organizationId): void
    {
        $connection = DB::connection(app(TenantContext::class)->connectionName());
        $connection->table('organizations')->where('id', $organizationId)->lockForUpdate()->firstOrFail();
        $company = Organization::query()->findOrFail($organizationId);
        $usage = $connection->table('package_usages')->where('organization_id', $organizationId)->lockForUpdate()->first();
        $count = Document::query()->where('organization_id', $organizationId)->whereIn('type', ['sale', 'resale'])->count();
        $limit = $company->packageLimits()['sale_invoices'];
        if ($limit !== null && $count >= $limit) {
            throw ValidationException::withMessages(['package_limits' => "Sales invoice limit reached ({$limit}). Ask the superadmin to upgrade the package or increase this company's invoice allowance, or delete an unneeded invoice to free a slot."]);
        }
        $connection->table('package_usages')->updateOrInsert(['organization_id' => $organizationId], [
            'sale_invoices' => $count + 1, 'created_at' => $usage?->created_at ?? now(), 'updated_at' => now(),
        ]);
    }

    public function usage(Organization $company): array
    {
        return [
            'branches' => Branch::query()->where('organization_id', $company->id)->where('active', true)->count(),
            'users' => User::query()->where('organization_id', $company->id)->where('active', true)->where('role', '!=', UserRole::Superadmin->value)->count(),
            'sale_invoices' => Document::query()->where('organization_id', $company->id)->whereIn('type', ['sale', 'resale'])->count(),
        ];
    }
}
