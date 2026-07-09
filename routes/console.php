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
