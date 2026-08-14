<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        ['middleware' => ['auth:sanctum']],
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // NOTE: statefulApi() removed — this app authenticates entirely via
        // Sanctum personal access tokens (Bearer header), never SPA cookie
        // sessions. Leaving it on made Laravel treat any request whose Origin
        // matched a "stateful domain" (e.g. localhost:3000) as a cookie-based
        // SPA request and enforce CSRF on it, even though the frontend never
        // fetches/sends a CSRF cookie — causing "CSRF token mismatch" (419)
        // on POST endpoints like /api/payments/create, silently, since 419s
        // aren't logged by default.

        $middleware->alias([
            'verified' => \Illuminate\Auth\Middleware\EnsureEmailIsVerified::class,
            'admin' => \App\Http\Middleware\IsAdmin::class,
            'validate.input' => \App\Http\Middleware\ValidateInput::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
