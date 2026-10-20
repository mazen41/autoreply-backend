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
        // Public website-chat widgets need a tenant-owned origin allowlist,
        // which cannot be represented by the app-wide static CORS config.
        $middleware->prepend(\App\Http\Middleware\WebChatCors::class);
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
            'business.access' => \App\Http\Middleware\EnsureBusinessAccess::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })
    ->withSchedule(function (\Illuminate\Console\Scheduling\Schedule $schedule): void {
        $command = static function (string $signature, string $name, int $lockMinutes, callable $frequency) use ($schedule): void {
            $event = $schedule->command($signature);
            $frequency($event);
            $event->name($name)
                ->withoutOverlapping($lockMinutes)
                ->onFailure(static fn () => app(\App\Services\ScheduledFailureNotifier::class)->notify($name));
        };

        // All scheduler declarations live here so every task has one bounded
        // mutex and command failures share the same operational alert path.
        $schedule->job(new \App\Jobs\PollGmailInbox())
            ->everyTwoMinutes()
            ->name('poll-gmail')
            ->withoutOverlapping(5)
            ->onFailure(static fn () => app(\App\Services\ScheduledFailureNotifier::class)->notify('poll-gmail'));
        $command('gmail:renew-watch', 'renew-gmail-watch', 180, static fn ($event) => $event->daily());
        $command('monitor:failed-jobs', 'monitor-failed-jobs', 15, static fn ($event) => $event->everyFiveMinutes());
        $command('monitor:operations', 'monitor-operational-health', 15, static fn ($event) => $event->everyFiveMinutes());
        $command('backup:database', 'database-backup', 300, static fn ($event) => $event->dailyAt('02:00'));
        $command('analytics:daily', 'daily-analytics', 240, static fn ($event) => $event->dailyAt('01:00'));
        $command('email-campaigns:send-due', 'send-due-email-campaigns', 5, static fn ($event) => $event->everyMinute());
        $command('campaigns:send-due', 'send-due-campaigns', 5, static fn ($event) => $event->everyMinute());
        $command('usage:check', 'check-usage-limits', 120, static fn ($event) => $event->dailyAt('01:30'));
        $command('system:health-check', 'system-health-check', 90, static fn ($event) => $event->hourly());
        $command('workflows:run-time-triggers', 'run-time-workflows', 5, static fn ($event) => $event->everyMinute());
        $command('media:expire-inbound', 'expire-inbound-media', 180, static fn ($event) => $event->dailyAt('03:30'));
        $command('data:apply-retention', 'apply-business-data-retention', 180, static fn ($event) => $event->dailyAt('03:45'));
        $command('sequence:monitor-queue', 'monitor-sequence-queue', 15, static fn ($event) => $event->everyFiveMinutes());
        $command('sequences:reset-stuck-executions', 'reset-stuck-sequence-executions', 15, static fn ($event) => $event->everyFiveMinutes());
        $command('workflows:reset-stuck-executions', 'reset-stuck-workflow-executions', 15, static fn ($event) => $event->everyFiveMinutes());
        $command('sequences:check-no-reply', 'check-no-reply-sequences', 20, static fn ($event) => $event->everyTenMinutes());
        $command('salla:sync-orders', 'sync-salla-orders', 30, static fn ($event) => $event->everyFifteenMinutes());
        $command('salla:sync-customers', 'sync-salla-customers', 45, static fn ($event) => $event->everyThirtyMinutes());
        $command('db:create-performance-indexes', 'create-performance-indexes', 180, static fn ($event) => $event->dailyAt('03:00'));
        $command('telegram:resync-webhooks', 'resync-telegram-webhooks', 60, static fn ($event) => $event->twiceDaily(0, 12));
    })->create();
