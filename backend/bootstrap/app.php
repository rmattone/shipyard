<?php

use App\Http\Middleware\AuthorizeOrganizationWrites;
use App\Http\Middleware\EnsureOrganizationRole;
use App\Http\Middleware\SetOrganizationContext;
use Illuminate\Auth\Middleware\EnsureEmailIsVerified;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->api(prepend: [
            EnsureFrontendRequestsAreStateful::class,
        ]);

        // Applies the 'api' rate limiter (defined in AppServiceProvider) to
        // every API route
        $middleware->throttleApi();

        // update.sh puts the app in maintenance mode while it runs; the
        // settings page keeps polling this route to show progress.
        $middleware->preventRequestsDuringMaintenance(except: [
            'api/system/update-status',
        ]);

        $middleware->alias([
            'verified' => EnsureEmailIsVerified::class,
            'org.context' => SetOrganizationContext::class,
            'org.role' => EnsureOrganizationRole::class,
            'org.writes' => AuthorizeOrganizationWrites::class,
        ]);

        // The organization context must be bound BEFORE route model
        // binding runs, otherwise {server}/{application}/... bindings
        // resolve without tenant scoping and leak across organizations.
        $middleware->prependToPriorityList(
            SubstituteBindings::class,
            SetOrganizationContext::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
