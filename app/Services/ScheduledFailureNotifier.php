<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use App\Mail\ScheduledTaskFailureMail;

class ScheduledFailureNotifier
{
    public function notify(string $taskName): void
    {
        $recipient = config('services.operations.alert_email');
        $failedAt = now()->toIso8601String();

        Log::critical('Scheduled command failed', [
            'task' => $taskName,
            'failed_at' => $failedAt,
            'alert_recipient_configured' => is_string($recipient) && filter_var($recipient, FILTER_VALIDATE_EMAIL) !== false,
        ]);

        if (!is_string($recipient) || filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
            return;
        }

        try {
            Mail::to($recipient)->send(new ScheduledTaskFailureMail($taskName, $failedAt));
        } catch (\Throwable $exception) {
            Log::error('Could not send scheduled task failure alert', [
                'task' => $taskName,
                'exception' => get_class($exception),
            ]);
        }
    }
}
