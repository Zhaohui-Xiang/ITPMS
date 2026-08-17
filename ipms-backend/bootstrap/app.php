<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Register API middleware groups
        $middleware->api(prepend: [
            \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
        ]);

        // Register route aliases for middleware
        $middleware->alias([
            'permission' => \App\Http\Middleware\CheckPermission::class,
            'audit.log'  => \App\Http\Middleware\AuditLogger::class,
        ]);

        // Trust proxies (Nginx reverse proxy)
        $middleware->trustProxies(at: '*');

        // Return 401 JSON instead of redirecting to missing 'login' route
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Return JSON for all API exceptions
        $exceptions->shouldRenderJsonWhen(function () {
            return true;
        });
    })->create();
