<?php

namespace App\Providers;

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
        //
    }
}
