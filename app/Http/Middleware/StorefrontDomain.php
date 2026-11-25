<?php

namespace App\Http\Middleware;

use App\Support\StorefrontDomains;
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
        if ($companyId = StorefrontDomains::companyForHost($host)) {
            $request->attributes->set(StorefrontDomains::REQUEST_COMPANY, $companyId);
            abort_unless($request->is('/', 'sitemap.xml', 'online-store', 'online-store/*'), 404);
            config(['session.domain' => null]);
        }

        return $next($request);
    }
}
