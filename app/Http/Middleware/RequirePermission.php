<?php

namespace App\Http\Middleware;

use App\Permission;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequirePermission
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $allowed = collect($permissions)
            ->map(fn (string $permission) => Permission::tryFrom($permission))
            ->filter()
            ->contains(fn (Permission $permission) => $request->user()?->hasPermission($permission));

        abort_unless($allowed, 403, 'Your owner has not granted access to this area.');

        return $next($request);
    }
}
