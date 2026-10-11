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
    protected $signature = 'monitor:failed-jobs';

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
        $failedJobs = DB::table('failed_jobs')
            ->orderBy('failed_at', 'desc')
            ->limit(10)
            ->get();

        if ($failedCount === 0) {
            Log::info('No failed jobs to monitor');
            return Command::SUCCESS;
        }

        Log::warning('Failed jobs detected', [
            'count' => $failedCount,
            'jobs' => $failedJobs->map(function ($job) {
                return [
                    'id' => $job->id,
                    'queue' => $job->queue,
                    'failed_at' => $job->failed_at,
                ];
            })->toArray(),
        ]);

        Log::alert('ALERT: Failed jobs require attention', [
            'count' => $failedCount,
            'latest_failure' => $failedJobs->first()->failed_at,
        ]);

        $threshold = max(1, (int) config('services.operations.failed_job_alert_threshold', 1));
        if ($failedCount >= $threshold
            && Cache::add('ops:alert:failed_jobs', true, now()->addMinutes(max(1, (int) config('services.operations.alert_dedup_minutes', 60))))) {
            $notifier->notify("Failed queue jobs: {$failedCount} (alert threshold {$threshold}).");
        }

        return Command::SUCCESS;
    }
}
