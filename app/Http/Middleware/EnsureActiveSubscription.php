<?php

namespace App\Http\Middleware;

use App\Models\SupportImpersonation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveSubscription
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user?->isSuperadmin()) {
            return $next($request);
        }

        if (SupportImpersonation::activeFor($request)) {
            return $next($request);
        }

        if (! $user?->organization?->hasActiveSubscription()) {
            return response()->json([
                'message' => 'Your client subscription is not active. Contact support to restore workspace access.',
                'code' => 'subscription_inactive',
            ], 403);
        }

        return $next($request);
    }
}
