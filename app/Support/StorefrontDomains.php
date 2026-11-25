<?php

namespace App\Support;

use App\Models\Organization;
use Closure;
use Illuminate\Validation\Rule;

class StorefrontDomains
{
    public const REQUEST_COMPANY = 'storefront_domain_company';

    public static function companyForHost(string $host): ?int
    {
        if (self::isPlatformHost($host)) {
            return null;
        }

        $legacy = config('tenancy.storefront_domains', [])[$host] ?? null;
        if ($legacy !== null) {
            return (int) $legacy;
        }

        $id = Organization::query()->where('storefront_domain', $host)->value('id');

        return $id === null ? null : (int) $id;
    }

    public static function preferredHost(Organization $company): ?string
    {
        $legacy = array_search($company->id, config('tenancy.storefront_domains', []), false);

        return $company->storefront_domain ?? ($legacy === false ? null : $legacy);
    }

    public static function rules(?Organization $company = null): array
    {
        $unique = Rule::unique('organizations', 'storefront_domain');

        return [
            'bail', 'nullable', 'string', 'max:253',
            'regex:/\A(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}\z/D',
            $company ? $unique->ignore($company) : $unique,
            static function (string $attribute, string $host, Closure $fail): void {
                if (self::isPlatformHost($host) || array_key_exists($host, config('tenancy.storefront_domains', []))) {
                    $fail('This domain is reserved. Choose a different storefront domain.');
                }
            },
        ];
    }

    private static function isPlatformHost(string $host): bool
    {
        foreach (['base_domain', 'central_host'] as $key) {
            $platform = strtolower((string) config('tenancy.'.$key));
            if ($platform !== '' && ($host === $platform || str_ends_with($host, '.'.$platform))) {
                return true;
            }
        }

        return false;
    }
}
