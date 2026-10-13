<?php

namespace App\Providers;

use App\TenantContext;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(TenantContext::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        ResetPassword::createUrlUsing(function ($user, string $token): string {
            if (! config('tenancy.enabled')) {
                return url(route('password.reset', [
                    'token' => $token,
                    'email' => $user->getEmailForPasswordReset(),
                ], false));
            }

            $appUrl = parse_url((string) config('app.url'));
            $scheme = $appUrl['scheme'] ?? 'https';
            $port = isset($appUrl['port']) ? ':'.$appUrl['port'] : '';
            $host = $user->isSuperadmin()
                ? config('tenancy.central_host')
                : $user->organization_id.'.'.config('tenancy.base_domain');
            $query = http_build_query(['email' => $user->getEmailForPasswordReset()], '', '&', PHP_QUERY_RFC3986);

            return $scheme.'://'.$host.$port.'/password/reset/'.rawurlencode($token).'?'.$query;
        });
    }
}
