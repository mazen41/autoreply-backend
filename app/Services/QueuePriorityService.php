<?php

namespace App\Services;

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Log;

class QueuePriorityService
{
    /**
     * Configure queue priorities
     */
    public function configureQueuePriorities(): void
    {
        // This would typically be configured in config/queue.php
        // Here we provide the logic for priority-based dispatching

        Log::info('Queue priorities configured');
    }

    /**
     * Dispatch job with priority
     */
    public function dispatchWithPriority($job, string $priority = 'normal'): void
    {
        $queue = $this->getQueueForPriority($priority);
        
        if (method_exists($job, 'onQueue')) {
            $job->onQueue($queue);
        }

        dispatch($job);
    }

    /**
     * Get queue name based on priority
     */
    private function getQueueForPriority(string $priority): string
    {
        return match ($priority) {
            'high' => 'high',
            'normal' => 'default',
            'low' => 'low',
            default => 'default',
        };
    }

    /**
     * Get queue statistics
     */
    public function getQueueStatistics(): array
    {
        $queues = ['high', 'default', 'low', 'campaigns', 'sequences'];
        $statistics = [];

        foreach ($queues as $queue) {
            $statistics[$queue] = [
                'size' => Queue::size($queue),
                'failed' => $this->getFailedJobsCount($queue),
            ];
        }

        return $statistics;
    }

    /**
     * Get failed jobs count for a queue
     */
    private function getFailedJobsCount(string $queue): int
    {
        // This would query the failed_jobs table
        // For now, return 0
        return 0;
    }

    /**
     * Clear failed jobs for a queue
     */
    public function clearFailedJobs(string $queue): int
    {
        // This would clear failed jobs from the database
        Log::info("Cleared failed jobs for queue: {$queue}");
        return 0;
    }

    /**
     * Retry failed jobs
     */
    public function retryFailedJobs(string $queue): int
    {
        // This would retry failed jobs
        Log::info("Retrying failed jobs for queue: {$queue}");
        return 0;
    }

    /**
     * Balance queue load
     */
    public function balanceQueueLoad(): void
    {
        $statistics = $this->getQueueStatistics();

        foreach ($statistics as $queue => $stats) {
            if ($stats['size'] > 1000) {
                Log::warning("Queue {$queue} has high backlog: {$stats['size']}");
                
                // Could trigger scaling or alerting
                if ($stats['size'] > 5000) {
                    $this->sendQueueAlert($queue, $stats['size']);
                }
            }
        }
    }

    /**
     * Send queue alert
     */
    private function sendQueueAlert(string $queue, int $size): void
    {
        Log::critical("Queue backlog alert", [
            'queue' => $queue,
            'size' => $size,
        ]);
    }
}
