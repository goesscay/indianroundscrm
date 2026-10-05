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
        // MAIL_TRANSPORT=graph|log (log when Microsoft 365 is not configured: never touches the network)
        $this->app->bind(\App\Services\Mail\MailTransport::class, fn () => \App\Services\Mail\MailTransportResolver::make());
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
