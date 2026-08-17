<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

use App\Jobs\PollGmailInbox;
use Illuminate\Support\Facades\Schedule;

Schedule::job(new PollGmailInbox())->everyTwoMinutes()->name('poll-gmail')->withoutOverlapping();
Schedule::command('gmail:renew-watch')->daily()->name('renew-gmail-watch')->withoutOverlapping();
Schedule::command('monitor:failed-jobs')->everyFiveMinutes()->name('monitor-failed-jobs')->withoutOverlapping();
Schedule::command('backup:database')->dailyAt('02:00')->name('database-backup')->withoutOverlapping();

// New automation cron jobs
Schedule::command('analytics:daily')->dailyAt('01:00')->name('daily-analytics')->withoutOverlapping();
Schedule::command('email-campaigns:send-due')->everyMinute()->name('send-due-email-campaigns')->withoutOverlapping();
Schedule::command('campaigns:send-due')->everyMinute()->name('send-due-campaigns')->withoutOverlapping();
Schedule::command('usage:check')->dailyAt('01:30')->name('check-usage-limits')->withoutOverlapping();
Schedule::command('system:health-check')->hourly()->name('system-health-check')->withoutOverlapping();

// Performance optimization (run once manually or after migrations)
// Schedule::command('performance:create-indexes')->daily()->name('create-performance-indexes')->withoutOverlapping();
