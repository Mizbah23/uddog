<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveUser
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->active || $request->user()?->access_paused) {
            $pausedByPlatform = $request->user()?->access_paused === true;
            auth()->logout();

            return response()->json([
                'message' => $pausedByPlatform ? 'Your account has been paused by the platform superadmin.' : 'This user account is inactive.',
                'code' => $pausedByPlatform ? 'user_access_paused' : 'user_inactive',
            ], 403);
        }

        return $next($request);
    }
}
