<?php

namespace App\Models;

use App\UserRole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SupportImpersonation extends Model
{
    public const SESSION_KEY = 'support_impersonation_id';

    protected $fillable = [
        'impersonator_id',
        'impersonated_user_id',
        'organization_id',
        'ip_address',
        'user_agent',
        'ended_at',
    ];

    protected function casts(): array
    {
        return ['ended_at' => 'datetime'];
    }

    public function impersonator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'impersonator_id');
    }

    public function impersonatedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'impersonated_user_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public static function activeFor(Request $request): ?self
    {
        $id = $request->session()->get(self::SESSION_KEY);
        if (! $id || ! $request->user()) {
            return null;
        }

        $impersonation = self::query()
            ->with(['impersonator', 'impersonatedUser', 'organization'])
            ->whereNull('ended_at')
            ->where('impersonated_user_id', $request->user()->id)
            ->find($id);

        if (! $impersonation
            || ! $impersonation->impersonator?->active
            || $impersonation->impersonator?->access_paused
            || ! $impersonation->impersonatedUser?->active
            || $impersonation->impersonatedUser?->access_paused
            || ! ($impersonation->impersonator->isSuperadmin()
                || ($impersonation->impersonator->hasRole(UserRole::Admin)
                    && $impersonation->impersonator->organization_id === $impersonation->organization_id
                    && $impersonation->impersonatedUser->organization_id === $impersonation->organization_id
                    && $impersonation->impersonatedUser->hasRole(UserRole::Manager, UserRole::Staff)))) {
            $request->session()->forget(self::SESSION_KEY);
            $impersonation?->update(['ended_at' => now()]);
            Auth::logout();

            return null;
        }

        return $impersonation;
    }

    public static function endFor(Request $request): void
    {
        $impersonation = self::activeFor($request);
        $impersonation?->update(['ended_at' => now()]);
        $request->session()->forget(self::SESSION_KEY);
    }
}
