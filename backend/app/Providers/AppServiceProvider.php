<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Services are resolved via container auto-wiring. Do not add
        // singleton bindings for SSH-backed services: the queue worker is
        // long-lived and a singleton SSHService would leak per-server
        // connection state across jobs.
    }

    public function boot(): void
    {
        // Generous ceiling for the SPA (dashboard polling during deployments
        // is the heaviest legitimate consumer); still stops line-speed abuse.
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(120)->by($request->user()?->id ?: $request->ip());
        });

        // The panel holds SSH keys and git tokens for every managed server;
        // the single admin password must not be brute-forceable.
        RateLimiter::for('login', function (Request $request) {
            return Limit::perMinute(5)->by($request->ip());
        });
    }
}
