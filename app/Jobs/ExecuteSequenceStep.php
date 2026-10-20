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

    public $tries = 10;
    public $maxExceptions = 3;
    public $backoff = [30, 60, 120];
    public $timeout = 120;

    // Delete the job after max retries to prevent infinite retry loops
    public $deleteAfterMissing = true;

    private const LOCK_TTL_SECONDS = 180;
    private const PROCESSING_STALE_AFTER_SECONDS = 180;

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
        if ($execution->status === 'skipped') {
            return;
        }

        // A live processing record is probably owned by another worker. The
        // execution lock expires if a worker dies, so reclaim only after the
        // worker timeout has elapsed; otherwise release this duplicate job.
        if ($execution->status === 'processing' && $execution->updated_at?->gt(now()->subSeconds(self::PROCESSING_STALE_AFTER_SECONDS))) {
            $this->release(15);
            return;
        }

        $enrollment = $execution->enrollment;

        if ($enrollment?->sequence && (
            $enrollment->sequence->status === 'paused'
            || app(\App\Services\AutomationRunControl::class)->releaseIfPaused($this, (int) $enrollment->sequence->business_id)
        )) {
            if ($enrollment->sequence->status === 'paused') {
                $this->release(60);
            }
            return;
        }

        if (!$enrollment || !$enrollment->canContinue()) {
            $execution->markAsSkipped('enrollment_not_active');
            return;
        }

        $conversation = $enrollment->conversation;
        if (
            $conversation?->requires_human === true
            && \App\Models\FeatureFlag::isEnabled('phase5_sequence_takeover_guard', (int) $conversation->business_id)
        ) {
            $execution->markAsSkipped('human_takeover');
            $enrollment->stop('human_takeover');
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
        $lock = Cache::store('database')->lock($lockKey, self::LOCK_TTL_SECONDS);

        if (!$lock->get()) {
            Log::warning("Sequence step execution already in progress, skipping", [
                'execution_id' => $this->executionId,
                'enrollment_id' => $enrollment->id,
                'step_id' => $step->id,
            ]);
            $this->release(15);
            return;
        }

        try {
            // Mark as processing
            $execution->status = 'processing';
            $execution->metadata = array_merge($execution->metadata ?? [], [
                'processing_started_at' => now()->toIso8601String(),
            ]);
            $execution->save();

            // Execute the step - external provider calls happen inside but should be safe now
            // because we've moved the transaction boundary
            $executionService->executeStep($execution, $enrollment, $step);

        } catch (\Exception $e) {
            if ($e instanceof \App\Exceptions\AutomationPausedException) {
                $this->release(60);
                return;
            }
            $execution->markAsFailed($e->getMessage());
            // Do NOT fail the enrollment here — let the job retry first.
            // Enrollment is only failed in failed() after all retries exhausted.
            Log::error("Sequence step execution failed", [
                'execution_id' => $execution->id,
                'enrollment_id' => $enrollment->id,
                'step_id' => $step->id,
                'exception_type' => get_class($e),
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
            'exception_type' => get_class($exception),
        ]);
    }
}
