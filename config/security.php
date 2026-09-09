<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Trusted proxies
    |--------------------------------------------------------------------------
    |
    | When the application sits behind a reverse proxy or load balancer that
    | terminates HTTPS, Laravel must trust that proxy's X-Forwarded-* headers
    | to know the request was secure (secure cookies, generated URLs). Give a
    | comma-separated list of proxy IPs / CIDRs, or "*" only when the app is
    | reachable exclusively through the proxy. Leave empty when PHP-FPM /
    | Apache serves HTTPS directly.
    |
    */

    'trusted_proxies' => array_values(array_filter(array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', ''))))),

    /*
    |--------------------------------------------------------------------------
    | Response headers
    |--------------------------------------------------------------------------
    |
    | Conservative headers that Filament / Livewire tolerate. A
    | Content-Security-Policy is deliberately NOT set: Filament and Livewire
    | need deliberate tuning for one, and a broken policy is worse than none.
    | HSTS is only meaningful over HTTPS and is left to the web server.
    |
    */

    /*
    | Tables `php artisan app:production-check` requires. Null = the command's
    | built-in list (overridable for tests / future modules).
    */
    'preflight_required_tables' => null,

    'headers' => [
        'X-Content-Type-Options' => 'nosniff',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'X-Frame-Options' => 'SAMEORIGIN',
        'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
    ],

];
