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

class SequenceEnrollmentService
{
    public function enrollConversation(Sequence $sequence, Conversation $conversation, int $startStep = 0): SequenceEnrollment
    {
        // Check for duplicate active enrollment
        $existingEnrollment = SequenceEnrollment::forSequence($sequence->id)
            ->forConversation($conversation->id)
            ->active()
            ->first();

        if ($existingEnrollment) {
            throw new \Exception('Conversation already has an active enrollment in this sequence');
        }

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
    }

    public function enrollConversationWithoutDuplicateCheck(Sequence $sequence, Conversation $conversation, int $startStep = 0): SequenceEnrollment
    {
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

        $delayInSeconds = 0;

        if ($currentStep->isDelayStep()) {
            $delayInSeconds = $currentStep->getDelayInSeconds();
        }

        // Create step execution record
        $execution = SequenceStepExecution::create([
            'sequence_id' => $enrollment->sequence_id,
            'sequence_enrollment_id' => $enrollment->id,
            'sequence_step_id' => $currentStep->id,
            'status' => 'pending',
            'scheduled_at' => now()->addSeconds($delayInSeconds),
        ]);

        // Update enrollment next execution time
        $enrollment->scheduleNextExecution($delayInSeconds);

        // Dispatch job for execution
        if ($delayInSeconds > 0) {
            // Delayed job
            ExecuteSequenceStep::dispatch($execution->id)
                ->delay(now()->addSeconds($delayInSeconds));
        } else {
            // Immediate execution
            ExecuteSequenceStep::dispatch($execution->id);
        }
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
