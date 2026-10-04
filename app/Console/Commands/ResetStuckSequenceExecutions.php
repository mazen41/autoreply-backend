<?php

namespace App\Console\Commands;

use App\Models\SequenceStepExecution;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Reset stuck sequence step executions.
 *
 * When a worker crashes mid-execution, the execution record is left in
 * 'processing' state forever. This command finds executions stuck in
 * 'processing' for more than a threshold period and resets them to
 * 'pending' so they can be retried.
 *
 * Scheduled to run every 5 minutes via the scheduler.
 */
class ResetStuckSequenceExecutions extends Command
{
    protected $signature = 'sequences:reset-stuck-executions
                            {--minutes=10 : Minutes after which a processing execution is considered stuck}';

    protected $description = 'Reset stuck sequence step executions from processing back to pending';

    public function handle(): int
    {
        $minutes = (int) $this->option('minutes');
        $threshold = now()->subMinutes($minutes);

        $stuck = SequenceStepExecution::processing()
            ->where('updated_at', '<', $threshold)
            ->get();

        if ($stuck->isEmpty()) {
            $this->info('No stuck executions found.');
            return Command::SUCCESS;
        }

        $this->warn("Found {$stuck->count()} stuck execution(s) (processing for > {$minutes} minutes):");

        foreach ($stuck as $execution) {
            $this->line("  - Execution #{$execution->id} (enrollment {$execution->sequence_enrollment_id}, step {$execution->sequence_step_id})");
        }

        if ($this->confirm('Reset these executions to pending for retry?')) {
            $count = SequenceStepExecution::processing()
                ->where('updated_at', '<', $threshold)
                ->update(['status' => 'pending']);

            $this->info("Reset {$count} execution(s) to pending.");

            Log::info('Reset stuck sequence executions', [
                'count' => $count,
                'threshold_minutes' => $minutes,
            ]);
        }

        return Command::SUCCESS;
    }
}
