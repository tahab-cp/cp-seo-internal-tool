<?php

namespace App\Providers;

use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Reverse proxies are trusted only when listed in TRUSTED_PROXIES
        // (config/security.php); nothing is trusted by default.
        $proxies = (array) config('security.trusted_proxies', []);

        if ($proxies !== []) {
            TrustProxies::at(in_array('*', $proxies, true) ? '*' : $proxies);
        }
    }
}
