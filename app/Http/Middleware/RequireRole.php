<?php

namespace App\Http\Middleware;

use App\UserRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireRole
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $allowed = array_filter(array_map(fn (string $role) => UserRole::tryFrom($role), $roles));
        abort_unless($request->user()?->hasRole(...$allowed), 403, 'You do not have permission to perform this action.');

        return $next($request);
    }
}
