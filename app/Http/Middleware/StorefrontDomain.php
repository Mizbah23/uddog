<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class StorefrontDomain
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $host = strtolower($request->getHost());
        abort_if(in_array($host, ['nishajagro.bd.poolcaresaudi.com', 'www.nishajagro.bd.poolcaresaudi.com'], true), 404);
        if (array_key_exists($host, config('tenancy.storefront_domains', []))) {
            abort_unless($request->is('/', 'sitemap.xml', 'online-store', 'online-store/*'), 404);
            config(['session.domain' => null]);
        }

        return $next($request);
    }
}
