<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
    public function handle()
    {
        $failedJobs = DB::table('failed_jobs')
            ->orderBy('failed_at', 'desc')
            ->limit(10)
            ->get();

        if ($failedJobs->isEmpty()) {
            Log::info('No failed jobs to monitor');
            return Command::SUCCESS;
        }

        Log::warning('Failed jobs detected', [
            'count' => $failedJobs->count(),
            'jobs' => $failedJobs->map(function ($job) {
                return [
                    'id' => $job->id,
                    'queue' => $job->queue,
                    'exception' => substr($job->exception, 0, 200),
                    'failed_at' => $job->failed_at,
                ];
            })->toArray(),
        ]);

        // In production, you would send alerts here (Slack, email, PagerDuty, etc.)
        // For now, we just log the alert
        Log::alert('ALERT: Failed jobs require attention', [
            'count' => $failedJobs->count(),
            'latest_failure' => $failedJobs->first()->failed_at,
        ]);

        return Command::SUCCESS;
    }
}
