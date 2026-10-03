<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Models\Branch;
use App\Models\User;
use App\UserRole;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class UserManagementController extends Controller
{
    public function index(): JsonResponse
    {
        $actor = request()->user();
        $users = User::query()
            ->with('organization:id,name,subscription_status,subscription_ends_at,access_paused', 'accessibleBranches:id,name,code')
            ->when(! $actor->isSuperadmin(), fn ($query) => $query
                ->where('organization_id', $actor->organization_id)
                ->whereIn('role', [UserRole::Manager->value, UserRole::Staff->value]))
            ->orderBy('name')
            ->get();

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

        if (in_array($data['role'], [UserRole::Superadmin->value, UserRole::Admin->value], true)) {
            $data['permissions'] = null;
            $data['report_permissions'] = null;
            $branchIds = [];
        }

        $this->validateBranchAccess($branchIds, $data['role'], (int) $data['organization_id']);

        $user = User::create($data);
        $user->accessibleBranches()->sync($branchIds);

        return response()->json($user->load('organization:id,name', 'accessibleBranches:id,name,code'), 201);
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
        $branchIds = $data['branch_ids'] ?? $user->accessibleBranches()->pluck('branches.id')->all();
        unset($data['branch_ids']);

        if (! $actor->isSuperadmin()) {
            if (! in_array($data['role'], [UserRole::Manager->value, UserRole::Staff->value], true)) {
                throw ValidationException::withMessages(['role' => 'Owners can assign manager and staff roles only.']);
            }
            $data['organization_id'] = $actor->organization_id;
        }

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
        if (in_array($data['role'], [UserRole::Superadmin->value, UserRole::Admin->value], true)) {
            $data['permissions'] = null;
            $data['report_permissions'] = null;
            $branchIds = [];
        }

        $this->validateBranchAccess($branchIds, $data['role'], (int) $data['organization_id']);

        $user->update($data);
        $user->accessibleBranches()->sync($branchIds);

        return response()->json($user->fresh()->load('organization:id,name', 'accessibleBranches:id,name,code'));
    }

    public function updateAccess(Request $request, int $managedUser): JsonResponse
    {
        $data = $request->validate(['paused' => ['required', 'boolean']]);
        $user = User::query()
            ->whereIn('role', [UserRole::Admin->value, UserRole::Manager->value, UserRole::Staff->value])
            ->whereNotNull('organization_id')
            ->findOrFail($managedUser);
        $user->update(['access_paused' => $data['paused']]);

        return response()->json($user->fresh()->load('organization:id,name', 'accessibleBranches:id,name,code'));
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
