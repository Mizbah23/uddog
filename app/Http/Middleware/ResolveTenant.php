<?php

namespace App\Http\Middleware;

use App\Models\Organization;
use App\Models\SupportImpersonation;
use App\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveTenant
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('tenancy.enabled')) {
            return $next($request);
        }

        $host = strtolower($request->getHost());
        $centralHost = strtolower((string) config('tenancy.central_host'));
        $baseDomain = strtolower((string) config('tenancy.base_domain'));
        $context = app(TenantContext::class);

        if ($host === $centralHost || $host === $baseDomain) {
            $impersonation = SupportImpersonation::activeFor($request);
            if ($impersonation) {
                $context->activate($impersonation->organization);
            } elseif ($request->user() && ! $request->user()->isSuperadmin()) {
                abort(403, 'Open your company subdomain to use this account.');
            }
        } else {
            $suffix = '.'.$baseDomain;
            abort_unless(str_ends_with($host, $suffix), 404);
            $label = substr($host, 0, -strlen($suffix));
            abort_if($label === '' || str_contains($label, '.'), 404);

            $organization = Organization::query()
                ->whereNotNull('tenant_database')
                ->when(
                    ctype_digit($label),
                    fn ($query) => $query->whereKey((int) $label),
                    fn ($query) => $query->where('subdomain', $label),
                )->firstOrFail();
            abort_if($request->user() && ($request->user()->isSuperadmin() || $request->user()->organization_id !== $organization->id), 403);
            $context->activate($organization);
        }

        try {
            return $next($request);
        } finally {
            $context->clear();
        }
    }
}
