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

        $isConfigured = is_string($recipient) && filter_var($recipient, FILTER_VALIDATE_EMAIL) !== false;

        Log::critical('Scheduled command failed', [
            'task' => $taskName,
            'failed_at' => $failedAt,
            'alert_recipient_configured' => $isConfigured,
        ]);

        try {
            \App\Models\SystemHealthLog::create([
                'component' => 'scheduler',
                'status' => 'critical',
                'message' => 'A scheduled task reported a failure.',
                'details' => ['task' => $taskName, 'failed_at' => $failedAt],
                'is_resolved' => false,
            ]);
        } catch (\Throwable $exception) {
            Log::error('Could not persist scheduled task failure health event', [
                'task' => $taskName,
                'exception_type' => get_class($exception),
            ]);
        }

        if (!$isConfigured) {
            Log::warning('Scheduled failure alert email could not be sent: OPERATIONS_ALERT_EMAIL is not configured or invalid in .env', [
                'task' => $taskName,
            ]);
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
