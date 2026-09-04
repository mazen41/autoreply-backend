<?php

namespace App\Services;

use App\Models\Sequence;
use App\Models\SequenceEnrollment;
use App\Models\Conversation;
use App\Models\SequenceStep;
use App\Models\SequenceStepExecution;
use App\Jobs\ExecuteSequenceStep;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\QueryException;

class SequenceEnrollmentService
{
    public function enrollConversation(Sequence $sequence, Conversation $conversation, int $startStep = 1): SequenceEnrollment
    {
        // Check for duplicate active enrollment
        $existingEnrollment = SequenceEnrollment::forSequence($sequence->id)
            ->forConversation($conversation->id)
            ->active()
            ->first();

        if ($existingEnrollment) {
            throw new \Exception('Conversation already has an active enrollment in this sequence');
        }

        try {
            return DB::transaction(function () use ($sequence, $conversation, $startStep) {
                $enrollment = SequenceEnrollment::create([
                    'sequence_id' => $sequence->id,
                    'conversation_id' => $conversation->id,
                    'current_step' => $startStep,
                    'status' => 'active',
                    'started_at' => now(),
                    'next_execution_at' => now(),
                    'metadata' => [
                        'enrolled_by' => 'manual',
                        'conversation_sender' => $conversation->sender_name,
                    ],
                ]);

                // Queue first step execution
                $this->queueStepExecution($enrollment);

                return $enrollment;
            });
        } catch (QueryException $e) {
            // DB-level unique constraint (seq_enroll_unique_active) caught a
            // genuine race that the app-level check above missed. Treat it the
            // same as the app-level duplicate check above, not as an unexpected
            // DB error, so callers only ever need to catch one exception type.
            if ($this->isDuplicateActiveEnrollmentViolation($e)) {
                Log::info('SequenceEnrollmentService: DB constraint caught a concurrent duplicate enrollment', [
                    'sequence_id' => $sequence->id,
                    'conversation_id' => $conversation->id,
                ]);
                throw new \Exception('Conversation already has an active enrollment in this sequence');
            }
            throw $e;
        }
    }

    public function enrollConversationWithoutDuplicateCheck(Sequence $sequence, Conversation $conversation, int $startStep = 1): SequenceEnrollment
    {
        try {
            return DB::transaction(function () use ($sequence, $conversation, $startStep) {
                $enrollment = SequenceEnrollment::create([
                    'sequence_id' => $sequence->id,
                    'conversation_id' => $conversation->id,
                    'current_step' => $startStep,
                    'status' => 'active',
                    'started_at' => now(),
                    'next_execution_at' => now(),
                    'metadata' => [
                        'enrolled_by' => 'automatic',
                        'conversation_sender' => $conversation->sender_name,
                    ],
                ]);

                // Queue first step execution
                $this->queueStepExecution($enrollment);

                return $enrollment;
            });
        } catch (QueryException $e) {
            // This method skips the app-level check by design, but the DB
            // constraint still applies — surface it the same way rather than
            // letting a raw SQL exception bubble up to callers.
            if ($this->isDuplicateActiveEnrollmentViolation($e)) {
                Log::info('SequenceEnrollmentService: DB constraint caught a duplicate enrollment (no-duplicate-check path)', [
                    'sequence_id' => $sequence->id,
                    'conversation_id' => $conversation->id,
                ]);
                throw new \Exception('Conversation already has an active enrollment in this sequence');
            }
            throw $e;
        }
    }

    /**
     * Detect whether a QueryException was caused by the seq_enroll_unique_active
     * constraint specifically, so we don't accidentally swallow unrelated DB
     * errors (e.g. a genuine connection failure or a different constraint).
     */
    private function isDuplicateActiveEnrollmentViolation(QueryException $e): bool
    {
        // MySQL duplicate-entry SQLSTATE is 23000; error code 1062 is
        // "Duplicate entry ... for key". We also check the constraint name
        // to be specific rather than matching on any 1062 error.
        return $e->getCode() === '23000'
            && str_contains($e->getMessage(), 'seq_enroll_unique_active');
    }

    public function unenrollConversation(SequenceEnrollment $enrollment, string $reason = 'manual'): bool
    {
        return DB::transaction(function () use ($enrollment, $reason) {
            $enrollment->stop($reason);

            // Cancel pending step executions
            $enrollment->executions()
                ->pending()
                ->each(function ($execution) {
                    $execution->markAsSkipped('unenrolled');
                });

            return true;
        });
    }

    public function getEnrollmentsForSequence(Sequence $sequence, array $filters = [])
    {
        $query = $sequence->enrollments();

        if (isset($filters['status'])) {
            if ($filters['status'] === 'active') {
                $query->active();
            } elseif ($filters['status'] === 'completed') {
                $query->completed();
            } elseif ($filters['status'] === 'stopped') {
                $query->stopped();
            } elseif ($filters['status'] === 'failed') {
                $query->failed();
            }
        }

        return $query->with(['conversation', 'sequence'])
            ->orderBy('created_at', 'desc')
            ->get();
    }

    public function getEnrollmentDetails(SequenceEnrollment $enrollment): array
    {
        $executions = $enrollment->executions()
            ->with(['step', 'message'])
            ->orderBy('created_at')
            ->get();

        return [
            'enrollment' => $enrollment,
            'conversation' => $enrollment->conversation,
            'sequence' => $enrollment->sequence,
            'current_step' => $enrollment->getCurrentStep(),
            'progress' => $enrollment->getProgress(),
            'executions' => $executions,
            'total_executions' => $executions->count(),
            'successful_executions' => $executions->where('status', 'executed')->count(),
            'failed_executions' => $executions->where('status', 'failed')->count(),
        ];
    }

    public function stopEnrollmentsForSequence(Sequence $sequence, string $reason = 'sequence_stopped'): int
    {
        return DB::transaction(function () use ($sequence, $reason) {
            $count = $sequence->activeEnrollments()->count();

            $sequence->activeEnrollments()->each(function ($enrollment) use ($reason) {
                $this->unenrollConversation($enrollment, $reason);
            });

            return $count;
        });
    }

    public function stopEnrollmentsForConversation(Conversation $conversation, string $reason = 'conversation_stopped'): int
    {
        return DB::transaction(function () use ($conversation, $reason) {
            $count = SequenceEnrollment::forConversation($conversation->id)
                ->active()
                ->count();

            SequenceEnrollment::forConversation($conversation->id)
                ->active()
                ->each(function ($enrollment) use ($reason) {
                    $this->unenrollConversation($enrollment, $reason);
                });

            return $count;
        });
    }

    public function queueStepExecution(SequenceEnrollment $enrollment): void
    {
        $currentStep = $enrollment->getCurrentStep();

        if (!$currentStep) {
            // No current step, complete the enrollment
            $enrollment->complete();
            return;
        }

        // Always execute immediately - delays are handled when moving to next step
        $delayInSeconds = 0;

        // Create step execution record
        $execution = SequenceStepExecution::create([
            'sequence_id' => $enrollment->sequence_id,
            'sequence_enrollment_id' => $enrollment->id,
            'sequence_step_id' => $currentStep->id,
            'status' => 'pending',
            'scheduled_at' => now()->addSeconds($delayInSeconds),
        ]);

        Log::info("QueueStepExecution: Created execution record", [
            'execution_id' => $execution->id,
            'enrollment_id' => $enrollment->id,
            'step_id' => $currentStep->id,
            'step_type' => $currentStep->step_type,
            'scheduled_at' => $execution->scheduled_at,
        ]);

        // Update enrollment next execution time
        $enrollment->scheduleNextExecution($delayInSeconds);

        // Dispatch job for immediate execution
        ExecuteSequenceStep::dispatch($execution->id);
        
        Log::info("QueueStepExecution: Dispatched immediate job", [
            'execution_id' => $execution->id,
        ]);
    }

    public function canEnroll(Sequence $sequence, Conversation $conversation): bool
    {
        // Check if sequence is active
        if ($sequence->status !== 'active') {
            return false;
        }

        // Check if sequence has steps
        if ($sequence->steps()->count() === 0) {
            return false;
        }

        // Check for existing active enrollment
        $existingEnrollment = SequenceEnrollment::forSequence($sequence->id)
            ->forConversation($conversation->id)
            ->active()
            ->first();

        if ($existingEnrollment) {
            return false;
        }

        return true;
    }

    public function getEnrollmentStatsForSequence(Sequence $sequence): array
    {
        $enrollments = $sequence->enrollments();

        return [
            'total' => $enrollments->count(),
            'active' => $enrollments->active()->count(),
            'completed' => $enrollments->completed()->count(),
            'stopped' => $enrollments->stopped()->count(),
            'failed' => $enrollments->failed()->count(),
        ];
    }
}
