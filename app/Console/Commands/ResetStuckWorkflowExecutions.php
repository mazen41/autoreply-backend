<?php

namespace App\Console\Commands;

use App\Models\WorkflowExecution;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Reset stuck workflow executions.
 *
 * When a worker crashes mid-execution, the WorkflowExecution record is
 * left in 'running' state forever. This command finds executions stuck
 * in 'running' for more than a threshold period and marks them as
 * 'failed' with an appropriate error message.
 *
 * Non-interactive by default so the scheduler (non-TTY) can run it.
 * Use --dry-run to only report stuck executions.
 *
 * Scheduled to run every 5 minutes via the scheduler.
 */
class ResetStuckWorkflowExecutions extends Command
{
    protected $signature = 'workflows:reset-stuck-executions
                            {--minutes=10 : Minutes after which a running execution is considered stuck}
                            {--dry-run : Only report stuck executions without updating them}';

    protected $description = 'Reset stuck workflow executions from running to failed';

    public function handle(): int
    {
        $minutes = (int) $this->option('minutes');
        $dryRun = (bool) $this->option('dry-run');
        $threshold = now()->subMinutes($minutes);

        $stuck = WorkflowExecution::where('status', 'running')
            ->where('updated_at', '<', $threshold)
            ->get();

        if ($stuck->isEmpty()) {
            $this->info('No stuck workflow executions found.');
            return Command::SUCCESS;
        }

        $this->warn("Found {$stuck->count()} stuck workflow execution(s) (running for > {$minutes} minutes):");

        foreach ($stuck as $execution) {
            $this->line("  - Execution #{$execution->id} (workflow {$execution->workflow_id}, conversation {$execution->conversation_id})");
        }

        if ($dryRun) {
            $this->info('Dry run — no changes made.');
            return Command::SUCCESS;
        }

        $count = WorkflowExecution::where('status', 'running')
            ->where('updated_at', '<', $threshold)
            ->update([
                'status' => 'failed',
                'error_message' => 'Worker crashed — execution marked as failed by reset command',
                'completed_at' => now(),
            ]);

        $this->info("Marked {$count} execution(s) as failed.");

        Log::info('Reset stuck workflow executions', [
            'count' => $count,
            'threshold_minutes' => $minutes,
        ]);

        return Command::SUCCESS;
    }
}
