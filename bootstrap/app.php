<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // The HIMS pages and their API (/backend/api.php). Registered without the "web" group, so no
        // Laravel session or CSRF token is involved: HIMS keeps its own login session and checks.
        then: function () {
            Route::group([], base_path('routes/hims.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'integration.key' => \App\Http\Middleware\EnsureIntegrationKey::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Partner systems always get JSON errors from /api/*.
        $exceptions->shouldRenderJsonWhen(fn ($request) => $request->is('api/*') || $request->expectsJson());
    })->create();
