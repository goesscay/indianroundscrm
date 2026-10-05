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
        // Named limiters (separate buckets; per-account lockout lives in AuthController).
        RateLimiter::for('auth-login', fn (Request $r) => Limit::perMinute(60)->by('login|'.$r->ip()));
        RateLimiter::for('chat-send', fn (Request $r) => Limit::perMinute(60)->by('chat|'.($r->user()?->id ?? $r->ip())));
        RateLimiter::for('auth-reset', fn (Request $r) => Limit::perMinute(30)->by('reset|'.$r->ip()));
    }
}
