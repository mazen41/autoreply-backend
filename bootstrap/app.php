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
            'plan.enforce' => \App\Http\Middleware\PlanEnforcement::class,
            'feature.flag' => \App\Http\Middleware\CheckFeatureFlag::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })
    ->withSchedule(function (\Illuminate\Console\Scheduling\Schedule $schedule): void {
        // Every minute: process due campaign messages
        $schedule->command('campaigns:send-due')->everyMinute()->withoutOverlapping();

        // Every minute: send due email campaigns
        $schedule->command('email-campaigns:send-due')->everyMinute()->withoutOverlapping();

        // Every 5 minutes: monitor sequence queue health
        $schedule->command('sequences:monitor-queue')->everyFiveMinutes()->withoutOverlapping();

        // Every 5 minutes: reset stuck sequence executions (worker crash recovery)
        $schedule->command('sequences:reset-stuck-executions')->everyFiveMinutes()->withoutOverlapping();

        // Every 5 minutes: reset stuck workflow executions (worker crash recovery)
        $schedule->command('workflows:reset-stuck-executions')->everyFiveMinutes()->withoutOverlapping();

        // Every 10 minutes: check for stuck no-reply sequences
        $schedule->command('sequences:check-no-reply')->everyTenMinutes()->withoutOverlapping();

        // Every 15 minutes: sync Salla orders
        $schedule->command('salla:sync-orders')->everyFifteenMinutes()->withoutOverlapping();

        // Every 30 minutes: sync Salla customers
        $schedule->command('salla:sync-customers')->everyThirtyMinutes()->withoutOverlapping();

        // Every hour: check system health
        $schedule->command('system:health-check')->hourly()->withoutOverlapping();

        // Every hour: check usage limits
        $schedule->command('billing:check-usage-limits')->hourly()->withoutOverlapping();

        // Daily at midnight: generate analytics
        $schedule->command('analytics:daily')->dailyAt('00:00')->withoutOverlapping();

        // Daily at 2 AM: backup database
        $schedule->command('backup:database')->dailyAt('02:00')->withoutOverlapping();

        // Daily at 3 AM: create performance indexes
        $schedule->command('db:create-performance-indexes')->dailyAt('03:00')->withoutOverlapping();

        // Every 6 hours: renew Gmail watch
        $schedule->command('gmail:renew-watch')->everySixHours()->withoutOverlapping();

        // Every 12 hours: resync Telegram webhooks
        $schedule->command('telegram:resync-webhooks')->everyTwelveHours()->withoutOverlapping();
    })->create();
