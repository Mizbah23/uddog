<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Permission;
use App\ReportType;
use App\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['organization_id', 'name', 'email', 'mobile', 'password', 'role', 'active', 'access_paused', 'permissions', 'report_permissions'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'active' => 'boolean',
            'access_paused' => 'boolean',
            'permissions' => 'array',
            'report_permissions' => 'array',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function accessibleBranches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'branch_user_access')->withTimestamps();
    }

    public function isSuperadmin(): bool
    {
        return $this->role === UserRole::Superadmin;
    }

    public function hasRole(UserRole ...$roles): bool
    {
        return in_array($this->role, $roles, true);
    }

    public function hasPermission(Permission $permission): bool
    {
        if ($this->hasRole(UserRole::Superadmin, UserRole::Admin)) {
            return true;
        }

        return in_array($permission->value, $this->permissions ?? [], true);
    }

    public function hasReportAccess(ReportType $report): bool
    {
        if (! collect($report->workspacePermissions())->contains(fn (Permission $permission) => $this->hasPermission($permission))) {
            return false;
        }

        return $this->report_permissions === null || in_array($report->value, $this->report_permissions, true);
    }
}
