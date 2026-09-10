<?php

namespace App\Providers;

use App\Support\EmailNormalizer;
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
        // ~5 failed login attempts per minute, keyed by email + IP.
        RateLimiter::for('login', function (Request $request) {
            $email = $request->has('email')
                ? EmailNormalizer::normalize((string) $request->input('email'))
                : '';

            return Limit::perMinute(5)->by($email.'|'.$request->ip());
        });
    }
}
