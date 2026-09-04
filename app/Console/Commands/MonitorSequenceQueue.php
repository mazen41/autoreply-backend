<?php

namespace App\Console\Commands;

use App\Models\SequenceStepExecution;
use App\Models\SequenceEnrollment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MonitorSequenceQueue extends Command
{
    protected $signature = 'sequence:monitor-queue';
    protected $description = 'Monitor sequence queue health and report metrics';

    public function handle(): int
    {
        $this->info('=== Sequence Queue Monitor ===');
        $this->newLine();

        // Get pending executions
        $pendingExecutions = SequenceStepExecution::pending()->count();
        $processingExecutions = SequenceStepExecution::processing()->count();
        $failedExecutions = SequenceStepExecution::failed()->count();
        $executedExecutions = SequenceStepExecution::executed()->count();

        $this->info('Sequence Step Executions:');
        $this->table(
            ['Status', 'Count'],
            [
                ['Pending', $pendingExecutions],
                ['Processing', $processingExecutions],
                ['Executed', $executedExecutions],
                ['Failed', $failedExecutions],
            ]
        );

        // Get enrollment status
        $activeEnrollments = SequenceEnrollment::where('status', 'active')->count();
        $completedEnrollments = SequenceEnrollment::where('status', 'completed')->count();
        $stoppedEnrollments = SequenceEnrollment::where('status', 'stopped')->count();
        $failedEnrollments = SequenceEnrollment::where('status', 'failed')->count();

        $this->newLine();
        $this->info('Sequence Enrollments:');
        $this->table(
            ['Status', 'Count'],
            [
                ['Active', $activeEnrollments],
                ['Completed', $completedEnrollments],
                ['Stopped', $stoppedEnrollments],
                ['Failed', $failedEnrollments],
            ]
        );

        // Check for stuck executions (processing for more than 1 hour)
        $stuckExecutions = SequenceStepExecution::processing()
            ->where('updated_at', '<', now()->subHour())
            ->count();

        if ($stuckExecutions > 0) {
            $this->newLine();
            $this->warn("⚠️  Found {$stuckExecutions} potentially stuck executions (processing for > 1 hour)");
            
            if ($this->confirm('Would you like to reset stuck executions to pending?')) {
                $resetCount = SequenceStepExecution::processing()
                    ->where('updated_at', '<', now()->subHour())
                    ->update(['status' => 'pending']);
                
                $this->info("✓ Reset {$resetCount} stuck executions to pending");
            }
        }

        // Check for overdue executions
        $overdueExecutions = SequenceStepExecution::pending()
            ->where('scheduled_at', '<', now())
            ->count();

        if ($overdueExecutions > 0) {
            $this->newLine();
            $this->warn("⚠️  Found {$overdueExecutions} overdue executions (scheduled time passed)");
        }

        // Health check
        $this->newLine();
        $this->info('Queue Health:');
        
        $healthScore = 100;
        $issues = [];

        if ($failedExecutions > 10) {
            $healthScore -= 20;
            $issues[] = 'High number of failed executions';
        }

        if ($stuckExecutions > 0) {
            $healthScore -= 30;
            $issues[] = 'Stuck executions detected';
        }

        if ($overdueExecutions > 5) {
            $healthScore -= 15;
            $issues[] = 'Many overdue executions';
        }

        if ($healthScore >= 80) {
            $this->info('✓ Health: GOOD (' . $healthScore . '%)');
        } elseif ($healthScore >= 50) {
            $this->warn('⚠️  Health: WARNING (' . $healthScore . '%)');
        } else {
            $this->error('✗ Health: CRITICAL (' . $healthScore . '%)');
        }

        if (!empty($issues)) {
            $this->newLine();
            $this->info('Issues detected:');
            foreach ($issues as $issue) {
                $this->warn('  - ' . $issue);
            }
        }

        // Log metrics
        Log::info('Sequence queue monitor metrics', [
            'pending_executions' => $pendingExecutions,
            'processing_executions' => $processingExecutions,
            'failed_executions' => $failedExecutions,
            'executed_executions' => $executedExecutions,
            'active_enrollments' => $activeEnrollments,
            'completed_enrollments' => $completedEnrollments,
            'stuck_executions' => $stuckExecutions,
            'overdue_executions' => $overdueExecutions,
            'health_score' => $healthScore,
        ]);

        return $healthScore >= 80 ? Command::SUCCESS : Command::FAILURE;
    }
}
