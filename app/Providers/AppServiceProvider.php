<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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
        RateLimiter::for('auth', function (Request $request) {
            $route = (string) ($request->route()?->uri() ?? '');
            $ip = (string) $request->ip();

            if (str_ends_with($route, '/auth/register')) {
                return Limit::perHour(10)->by('auth-register:' . $ip);
            }

            $email = mb_strtolower(trim((string) $request->input('email', '')));
            return [
                Limit::perMinute(5)->by('auth-email-ip:' . $email . ':' . $ip),
                Limit::perMinute(20)->by('auth-ip:' . $ip),
            ];
        });
    }
}
