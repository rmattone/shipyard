<?php

namespace App\Providers;

use App\Services\CertbotService;
use App\Services\DeploymentService;
use App\Services\GitLabService;
use App\Services\NginxService;
use App\Services\SSHService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SSHService::class);
        $this->app->singleton(GitLabService::class);

        $this->app->singleton(NginxService::class, function ($app) {
            return new NginxService($app->make(SSHService::class));
        });

        $this->app->singleton(CertbotService::class, function ($app) {
            return new CertbotService(
                $app->make(SSHService::class),
                $app->make(NginxService::class)
            );
        });

        $this->app->singleton(DeploymentService::class, function ($app) {
            return new DeploymentService(
                $app->make(SSHService::class),
                $app->make(NginxService::class)
            );
        });
    }

    public function boot(): void
    {
        //
    }
}
