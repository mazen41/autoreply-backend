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
use Illuminate\Support\Facades\Cache;

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

        // Concurrency lock: prevent multiple workers from executing the same step simultaneously
        $lockKey = "sequence_step_execution:{$enrollment->id}:{$step->id}";
        $lock = Cache::lock($lockKey, 60); // 60 second lock

        if (!$lock->get()) {
            Log::warning("Sequence step execution already in progress, skipping", [
                'execution_id' => $this->executionId,
                'enrollment_id' => $enrollment->id,
                'step_id' => $step->id,
            ]);
            return;
        }

        try {
            // Mark as processing
            $execution->status = 'processing';
            $execution->save();

            // Execute the step - external provider calls happen inside but should be safe now
            // because we've moved the transaction boundary
            $executionService->executeStep($execution, $enrollment, $step);

        } catch (\Exception $e) {
            $execution->markAsFailed($e->getMessage());
            // Do NOT fail the enrollment here — let the job retry first.
            // Enrollment is only failed in failed() after all retries exhausted.
            Log::error("Sequence step execution failed", [
                'execution_id' => $execution->id,
                'enrollment_id' => $enrollment->id,
                'step_id' => $step->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        } finally {
            $lock->release();
        }
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
