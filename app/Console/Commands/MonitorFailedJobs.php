<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use App\Services\ScheduledFailureNotifier;

class MonitorFailedJobs extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'monitor:failed-jobs {--force : Re-alert on existing failed jobs even if already alerted}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Monitor failed jobs and send alerts';

    /**
     * Execute the console command.
     */
    public function handle(ScheduledFailureNotifier $notifier)
    {
        $failedCount = DB::table('failed_jobs')->count();

        if ($failedCount === 0) {
            Cache::forget('ops:monitor:last_alerted_failed_job_id');
            Log::info('No failed jobs to monitor');
            $this->info('No failed jobs to monitor.');
            return Command::SUCCESS;
        }

        $failedJobs = DB::table('failed_jobs')
            ->orderBy('id', 'desc')
            ->limit(10)
            ->get();

        $latestJob = $failedJobs->first();
        $latestJobId = $latestJob ? (int) $latestJob->id : 0;
        $lastAlertedId = (int) Cache::get('ops:monitor:last_alerted_failed_job_id', 0);
        $force = (bool) $this->option('force');

        if (!$force && $lastAlertedId > 0 && $latestJobId <= $lastAlertedId) {
            Log::info('Failed jobs monitor: existing failed jobs already alerted', [
                'total_failed' => $failedCount,
                'last_alerted_id' => $lastAlertedId,
            ]);
            $this->info("No new failed jobs since job #{$lastAlertedId} (total: {$failedCount}).");
            return Command::SUCCESS;
        }

        $newCount = ($lastAlertedId > 0 && !$force)
            ? DB::table('failed_jobs')->where('id', '>', $lastAlertedId)->count()
            : $failedCount;

        $newJobs = ($lastAlertedId > 0 && !$force)
            ? DB::table('failed_jobs')->where('id', '>', $lastAlertedId)->orderBy('id', 'desc')->limit(10)->get()
            : $failedJobs;

        Log::warning('Failed jobs detected', [
            'new_count' => $newCount,
            'total_count' => $failedCount,
            'jobs' => $newJobs->map(function ($job) {
                return [
                    'id' => $job->id,
                    'queue' => $job->queue,
                    'failed_at' => $job->failed_at,
                ];
            })->toArray(),
        ]);

        Log::alert('ALERT: Failed jobs require attention', [
            'new_count' => $newCount,
            'total_count' => $failedCount,
            'latest_failure' => $latestJob?->failed_at,
        ]);

        $threshold = max(1, (int) config('services.operations.failed_job_alert_threshold', 1));
        if ($newCount >= $threshold) {
            $notifier->notify("Failed queue jobs: {$newCount} new ({$failedCount} total). Latest failure ID: #{$latestJobId}.");
        }

        Cache::forever('ops:monitor:last_alerted_failed_job_id', $latestJobId);

        $this->warn("Failed jobs detected: {$newCount} new ({$failedCount} total).");
        return Command::SUCCESS;
    }
}
