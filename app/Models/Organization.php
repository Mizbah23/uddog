<?php

namespace App\Models;

use App\SubscriptionStatus;
use App\UserRole;
use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory;

    protected $fillable = ['name', 'plan_name', 'subscription_status', 'subscription_ends_at', 'notes'];

    protected $appends = ['subscription_active'];

    protected function casts(): array
    {
        return [
            'subscription_status' => SubscriptionStatus::class,
            'subscription_ends_at' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::created(function (Organization $organization) {
            $organization->branches()->create([
                'name' => 'Main Branch',
                'code' => 'MAIN',
                'is_default' => true,
                'active' => true,
            ]);
        });
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function admins(): HasMany
    {
        return $this->hasMany(User::class)->where('role', UserRole::Admin->value);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    public function supportImpersonations(): HasMany
    {
        return $this->hasMany(SupportImpersonation::class);
    }

    public function hasActiveSubscription(): bool
    {
        return in_array($this->subscription_status, [SubscriptionStatus::Trial, SubscriptionStatus::Active], true)
            && (! $this->subscription_ends_at || $this->subscription_ends_at->isToday() || $this->subscription_ends_at->isFuture());
    }

    public function getSubscriptionActiveAttribute(): bool
    {
        return $this->hasActiveSubscription();
    }
}
