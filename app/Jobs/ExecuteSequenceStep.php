<?php

namespace App\Jobs;

use App\Models\SequenceStepExecution;
use App\Models\SequenceEnrollment;
use App\Models\SequenceStep;
use App\Models\Message;
use App\Models\Conversation;
use App\Services\SequenceExecutionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ExecuteSequenceStep implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $backoff = [30, 60, 120];
    public $timeout = 120;

    // Delete the job after max retries to prevent infinite retry loops
    public $deleteAfterMissing = true;

    public function __construct(
        public int $executionId
    ) {}

    public function handle(SequenceExecutionService $executionService): void
    {
        $execution = SequenceStepExecution::find($this->executionId);

        if (!$execution) {
            Log::warning("Sequence step execution not found: {$this->executionId}");
            return;
        }

        // Idempotency check - if already executed successfully, skip
        if ($execution->status === 'executed') {
            Log::info("Sequence step already executed, skipping", [
                'execution_id' => $this->executionId,
                'sequence_enrollment_id' => $execution->sequence_enrollment_id,
                'sequence_step_id' => $execution->sequence_step_id,
            ]);
            return;
        }

        $enrollment = $execution->enrollment;

        if (!$enrollment || !$enrollment->canContinue()) {
            $execution->markAsSkipped('enrollment_not_active');
            return;
        }

        $step = $execution->step;

        if (!$step || !$step->is_active) {
            $execution->markAsSkipped('step_not_active');
            // Move to next step
            $enrollment->moveToNextStep();
            return;
        }

        // Mark as processing to prevent concurrent execution
        $execution->status = 'processing';
        $execution->save();

        DB::transaction(function () use ($execution, $enrollment, $step, $executionService) {
            try {
                $executionService->executeStep($execution, $enrollment, $step);
            } catch (\Exception $e) {
                $execution->markAsFailed($e->getMessage());
                $enrollment->fail($e->getMessage());
                Log::error("Sequence step execution failed", [
                    'execution_id' => $execution->id,
                    'enrollment_id' => $enrollment->id,
                    'step_id' => $step->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                throw $e;
            }
        });
    }

    public function failed(\Throwable $exception): void
    {
        $execution = SequenceStepExecution::find($this->executionId);
        
        if ($execution) {
            // Mark as failed after all retries exhausted
            $execution->markAsFailed($exception->getMessage());
            
            // Stop the enrollment if this was a critical failure
            $enrollment = $execution->enrollment;
            if ($enrollment && $this->attempts() >= $this->tries) {
                $enrollment->fail('Max retries exceeded for step execution');
            }
        }

        Log::error("Sequence step job failed after all retries", [
            'execution_id' => $this->executionId,
            'attempts' => $this->attempts(),
            'max_tries' => $this->tries,
            'error' => $exception->getMessage(),
        ]);
    }
}
