<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Models\Branch;
use App\Models\Organization;
use App\Models\SupportImpersonation;
use App\Models\User;
use App\Services\TenantProvisioner;
use App\TenantContext;
use App\UserRole;
use Illuminate\Database\Connection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UserManagementController extends Controller
{
    private const USER_HISTORY_COLUMNS = [
        'documents' => ['created_by'],
        'expenses' => ['created_by'],
        'gold_jobs' => ['created_by'],
        'gold_rates' => ['created_by'],
        'payroll_runs' => ['created_by'],
        'product_damages' => ['created_by'],
        'purchase_payments' => ['created_by'],
        'sale_payments' => ['created_by'],
        'sales_targets' => ['created_by', 'user_id'],
        'stock_checks' => ['created_by', 'completed_by'],
        'stock_movements' => ['created_by'],
        'stock_transfers' => ['created_by'],
    ];

    public function index(): JsonResponse
    {
        $actor = request()->user();
        $query = User::query()
            ->with('organization:id,name,subscription_status,subscription_ends_at,access_paused,tenant_database')
            ->when(! $actor->isSuperadmin(), fn ($query) => $query
                ->where('organization_id', $actor->organization_id)
                ->whereIn('role', [UserRole::Manager->value, UserRole::Staff->value]))
            ->orderBy('name');

        if (! config('tenancy.enabled') || ! $actor->isSuperadmin()) {
            $query->with('accessibleBranches:id,name,code');
        }
        $users = $query->get();
        if (config('tenancy.enabled') && $actor->isSuperadmin()) {
            $users->each(function (User $user): void {
                if ($user->organization?->tenant_database && in_array($user->role, [UserRole::Manager, UserRole::Staff], true)) {
                    app(TenantContext::class)->run($user->organization, function () use ($user): void {
                        $user->load('accessibleBranches:id,name,code');
                        $user->setRelation('accessibleBranches', $user->accessibleBranches->map(fn ($branch) => $branch->toArray()));
                    });
                } else {
                    $user->setRelation('accessibleBranches', collect());
                }
            });
        }

        return response()->json($users);
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $actor = $request->user();
        $data = $request->validated();
        $branchIds = $data['branch_ids'] ?? [];
        unset($data['branch_ids']);

        if (! $actor->isSuperadmin()) {
            if (! in_array($data['role'], [UserRole::Manager->value, UserRole::Staff->value], true)) {
                throw ValidationException::withMessages(['role' => 'Owners can create manager and staff accounts only.']);
            }
            $data['organization_id'] = $actor->organization_id;
        }

        if ($data['role'] !== UserRole::Superadmin->value && empty($data['organization_id'])) {
            throw ValidationException::withMessages(['organization_id' => 'Select a client account for this user.']);
        }

        if ($data['role'] === UserRole::Superadmin->value) {
            $data['permissions'] = null;
            $data['report_permissions'] = null;
            $branchIds = [];
        } elseif ($data['role'] === UserRole::Admin->value) {
            $branchIds = [];
        }

        $company = $data['role'] !== UserRole::Superadmin->value && ! empty($data['organization_id'])
            ? Organization::query()->findOrFail($data['organization_id']) : null;
        $save = function () use ($branchIds, $data): JsonResponse {
            $this->validateBranchAccess($branchIds, $data['role'], (int) ($data['organization_id'] ?? 0));
            $user = User::create($data);
            app(TenantProvisioner::class)->syncUserShadow($user);
            if (! config('tenancy.enabled') || $data['role'] !== UserRole::Superadmin->value) {
                $user->accessibleBranches()->sync($branchIds);
                $user->load('accessibleBranches:id,name,code');
            } else {
                $user->setRelation('accessibleBranches', collect());
            }

            return response()->json($user->load('organization:id,name'), 201);
        };

        return $company ? app(TenantContext::class)->run($company, $save) : $save();
    }

    public function update(UpdateUserRequest $request, int $managedUser): JsonResponse
    {
        $actor = $request->user();
        $user = User::query()
            ->when(! $actor->isSuperadmin(), fn ($query) => $query
                ->where('organization_id', $actor->organization_id)
                ->whereIn('role', [UserRole::Manager->value, UserRole::Staff->value]))
            ->findOrFail($managedUser);
        $data = $request->validated();

        if (! $actor->isSuperadmin()) {
            if (! in_array($data['role'], [UserRole::Manager->value, UserRole::Staff->value], true)) {
                throw ValidationException::withMessages(['role' => 'Owners can assign manager and staff roles only.']);
            }
            $data['organization_id'] = $actor->organization_id;
        }

        if (config('tenancy.enabled') && (int) ($data['organization_id'] ?? 0) !== (int) ($user->organization_id ?? 0)) {
            throw ValidationException::withMessages(['organization_id' => 'Moving an existing account between company databases is not supported. Create an account in the destination company instead.']);
        }

        if (config('tenancy.enabled') && $user->isSuperadmin() !== ($data['role'] === UserRole::Superadmin->value)) {
            throw ValidationException::withMessages(['role' => 'Platform superadmin accounts cannot be converted to or from company accounts.']);
        }

        $company = $data['role'] !== UserRole::Superadmin->value && ! empty($data['organization_id'])
            ? Organization::query()->findOrFail($data['organization_id']) : null;
        $branchIds = $data['branch_ids'] ?? ($company
            ? app(TenantContext::class)->run($company, fn () => $user->accessibleBranches()->pluck('branches.id')->all())
            : []);
        unset($data['branch_ids']);

        if ($data['role'] !== UserRole::Superadmin->value && empty($data['organization_id'])) {
            throw ValidationException::withMessages(['organization_id' => 'Select a client account for this user.']);
        }
        if ($user->is($actor) && ! $data['active']) {
            throw ValidationException::withMessages(['active' => 'You cannot deactivate your own account.']);
        }
        if ($user->isSuperadmin() && ($data['role'] !== UserRole::Superadmin->value || ! $data['active'])
            && User::query()->where('role', UserRole::Superadmin->value)->where('active', true)->count() === 1) {
            throw ValidationException::withMessages(['role' => 'At least one active superadmin is required.']);
        }
        if (empty($data['password'])) {
            unset($data['password']);
        }
        if ($data['role'] === UserRole::Superadmin->value) {
            $data['permissions'] = null;
            $data['report_permissions'] = null;
            $branchIds = [];
        } elseif ($data['role'] === UserRole::Admin->value) {
            $branchIds = [];
        }

        $save = function () use ($branchIds, $data, $user): JsonResponse {
            $this->validateBranchAccess($branchIds, $data['role'], (int) ($data['organization_id'] ?? 0));
            $user->update($data);
            app(TenantProvisioner::class)->syncUserShadow($user);
            if (! config('tenancy.enabled') || $data['role'] !== UserRole::Superadmin->value) {
                $user->accessibleBranches()->sync($branchIds);
                $user->load('accessibleBranches:id,name,code');
            } else {
                $user->setRelation('accessibleBranches', collect());
            }

            $user->load('organization:id,name');

            return response()->json($user);
        };

        return $company ? app(TenantContext::class)->run($company, $save) : $save();
    }

    public function updateAccess(Request $request, int $managedUser): JsonResponse
    {
        $data = $request->validate(['paused' => ['required', 'boolean']]);
        $user = User::query()
            ->whereIn('role', [UserRole::Admin->value, UserRole::Manager->value, UserRole::Staff->value])
            ->whereNotNull('organization_id')
            ->findOrFail($managedUser);
        $user->update(['access_paused' => $data['paused']]);
        $user->refresh()->load('organization:id,name');
        app(TenantContext::class)->run($user->organization, function () use ($user): void {
            $user->load('accessibleBranches:id,name,code');
            if (config('tenancy.enabled')) {
                $user->setRelation('accessibleBranches', $user->accessibleBranches->map(fn ($branch) => $branch->toArray()));
            }
        });

        return response()->json($user);
    }

    public function impersonate(Request $request, int $managedUser): JsonResponse
    {
        $actor = $request->user();
        $user = User::query()
            ->where('organization_id', $actor->organization_id)
            ->whereIn('role', [UserRole::Manager->value, UserRole::Staff->value])
            ->findOrFail($managedUser);

        if (! $user->active || $user->access_paused) {
            throw ValidationException::withMessages(['user' => 'Only an active, unpaused user can be viewed.']);
        }

        $impersonation = SupportImpersonation::create([
            'impersonator_id' => $actor->id,
            'impersonated_user_id' => $user->id,
            'organization_id' => $actor->organization_id,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        $request->session()->put(SupportImpersonation::SESSION_KEY, $impersonation->id);
        Auth::login($user);
        $request->session()->regenerate();

        return response()->json([
            'user' => $user->load('organization'),
            'message' => "You are now viewing the workspace as {$user->name}.",
        ]);
    }

    public function destroy(Request $request, int $managedUser): JsonResponse
    {
        $actor = $request->user();
        $user = User::query()
            ->where('organization_id', $actor->organization_id)
            ->whereIn('role', [UserRole::Manager->value, UserRole::Staff->value])
            ->findOrFail($managedUser);

        if ($user->active) {
            throw ValidationException::withMessages(['user' => 'Deactivate this user before permanently deleting the account.']);
        }
        $central = DB::connection(config('tenancy.central_connection'));
        $company = $user->organization;

        $central->transaction(function () use ($central, $company, $user): void {
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);
            if ($locked->active || $this->hasBusinessHistory($central, $locked->id)) {
                throw ValidationException::withMessages(['user' => 'This user is active or has recorded activity and cannot be permanently deleted.']);
            }

            if (config('tenancy.enabled') && $company?->tenant_database) {
                app(TenantContext::class)->run($company, function () use ($user): void {
                    $tenant = DB::connection('tenant');
                    $tenant->transaction(function () use ($tenant, $user): void {
                        if ($this->hasBusinessHistory($tenant, $user->id)) {
                            throw ValidationException::withMessages(['user' => 'This user has recorded activity and cannot be permanently deleted.']);
                        }
                        $tenant->table('users')->where('id', $user->id)->delete();
                    });
                });
            }

            SupportImpersonation::query()->where('impersonated_user_id', $user->id)->delete();
            $central->table('sessions')->where('user_id', $user->id)->delete();
            if ($user->email) {
                $central->table('password_reset_tokens')->where('email', $user->email)->delete();
            }
            $locked->delete();
        });

        return response()->json(['ok' => true]);
    }

    private function hasBusinessHistory(Connection $connection, int $userId): bool
    {
        foreach (self::USER_HISTORY_COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                if ($connection->table($table)->where($column, $userId)->exists()) {
                    return true;
                }
            }
        }

        return false;
    }

    private function validateBranchAccess(array $branchIds, string $role, int $organizationId): void
    {
        if (! in_array($role, [UserRole::Manager->value, UserRole::Staff->value], true)) {
            return;
        }
        if ($branchIds === []) {
            throw ValidationException::withMessages(['branch_ids' => 'Select at least one branch for a manager or staff member.']);
        }
        if (Branch::query()->where('organization_id', $organizationId)->where('active', true)->whereIn('id', $branchIds)->count() !== count($branchIds)) {
            throw ValidationException::withMessages(['branch_ids' => 'Every selected branch must be an active branch in this company.']);
        }
    }
}
