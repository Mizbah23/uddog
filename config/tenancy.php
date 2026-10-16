<?php

return [
    'enabled' => (bool) env('TENANCY_ENABLED', false),
    'base_domain' => env('TENANCY_BASE_DOMAIN', 'uddog-soft.com'),
    'central_host' => env('TENANCY_CENTRAL_HOST', 'uddog-soft.com'),
    'central_connection' => env('DB_CONNECTION', 'mysql'),
    'database_prefix' => env('TENANCY_DATABASE_PREFIX', 'uddog_company_'),
    'sqlite_directory' => env('TENANCY_SQLITE_DIRECTORY', database_path('tenants')),
];
