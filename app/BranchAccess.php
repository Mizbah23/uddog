<?php

namespace App;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class BranchAccess
{
    public function query(User $user, int $organizationId): Builder
    {
        return Branch::query()
            ->where('organization_id', $organizationId)
            ->when(! $this->canAccessAll($user), fn (Builder $query) => $query->whereHas('users', fn (Builder $users) => $users->whereKey($user->id)));
    }

    public function ids(User $user, int $organizationId): array
    {
        return $this->query($user, $organizationId)->pluck('id')->all();
    }

    public function activeBranch(User $user, int $organizationId, ?int $branchId = null): Branch
    {
        $branch = $this->query($user, $organizationId)
            ->where('active', true)
            ->when($branchId, fn (Builder $query) => $query->whereKey($branchId))
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->first();

        if (! $branch) {
            throw ValidationException::withMessages(['branch_id' => 'You do not have access to the selected active branch.']);
        }

        return $branch;
    }

    public function ensure(User $user, int $organizationId, int $branchId): void
    {
        $this->activeBranch($user, $organizationId, $branchId);
    }

    private function canAccessAll(User $user): bool
    {
        return $user->hasRole(UserRole::Superadmin, UserRole::Admin);
    }
}
