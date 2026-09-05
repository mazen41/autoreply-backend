<?php

namespace App\Jobs;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\Sequence;
use App\Models\SequenceEnrollment;
use App\Services\SequenceEnrollmentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * CheckNoReplyForMessage
 *
 * Dispatched by ProcessAutoReply immediately after the AI sends a reply,
 * with a queue delay equal to the no-reply sequence's configured wait time.
 *
 * When this job fires it checks:
 *   1. The AI reply ($aiReplyMessageId) still exists.
 *   2. No newer INBOUND message has arrived in the conversation since
 *      the AI reply was sent (i.e. the customer still hasn't replied).
 *   3. The no_reply_cycle stored on any existing enrollment still matches
 *      $expectedCycle — meaning no new cycle was started since this job
 *      was dispatched (which would happen if the customer replied and then
 *      the AI replied again).
 *
 * If all conditions pass → enroll the conversation in the no-reply sequence
 * (or re-enroll if allow_reentry is true).
 *
 * If conditions fail → log and exit without enrolling. This is the correct
 * "cancel" path for stale timers; no explicit cancellation mechanism is
 * needed because the conditions act as a gate.
 *
 * Idempotency: The DB-level unique index on (sequence_id, conversation_id)
 * for active enrollments plus the cycle-number check prevents duplicate
 * enrollments even if the job runs twice (queue retry, duplicate webhook, etc.)
 */
class CheckNoReplyForMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 60;

    public function __construct(
        public readonly int    $sequenceId,
        public readonly int    $conversationId,
        public readonly int    $aiReplyMessageId,   // outbound AI Message.id that started this timer
        public readonly int    $expectedCycle,       // no_reply_cycle at dispatch time
    ) {}

    public function handle(SequenceEnrollmentService $enrollmentService): void
    {
        $sequence     = Sequence::find($this->sequenceId);
        $conversation = Conversation::find($this->conversationId);
        $aiReply      = Message::find($this->aiReplyMessageId);

        // --- Guard: basic model existence ---
        if (!$sequence || !$conversation || !$aiReply) {
            Log::info('CheckNoReplyForMessage: models missing, skipping', [
                'sequence_id'      => $this->sequenceId,
                'conversation_id'  => $this->conversationId,
                'ai_reply_id'      => $this->aiReplyMessageId,
            ]);
            return;
        }

        if ($sequence->status !== 'active') {
            Log::info('CheckNoReplyForMessage: sequence no longer active, skipping', [
                'sequence_id' => $this->sequenceId,
            ]);
            return;
        }

        // --- Guard: has customer replied since the AI message? ---
        $customerReplied = Message::where('conversation_id', $this->conversationId)
            ->where('direction', 'inbound')
            ->where('id', '>', $this->aiReplyMessageId)
            ->exists();

        if ($customerReplied) {
            Log::info('CheckNoReplyForMessage: customer replied since AI message — no-reply timer cancelled', [
                'sequence_id'     => $this->sequenceId,
                'conversation_id' => $this->conversationId,
                'ai_reply_id'     => $this->aiReplyMessageId,
                'expected_cycle'  => $this->expectedCycle,
            ]);
            return;
        }

        // --- Guard: cycle check — did a new cycle start since dispatch? ---
        // A new cycle means: customer replied → AI replied again → new job
        // dispatched with expectedCycle+1. The current job is stale.
        $currentCycle = SequenceEnrollment::where('sequence_id', $this->sequenceId)
            ->where('conversation_id', $this->conversationId)
            ->max('no_reply_cycle') ?? 0;

        if ($currentCycle > $this->expectedCycle) {
            Log::info('CheckNoReplyForMessage: newer cycle exists — stale timer, skipping', [
                'sequence_id'    => $this->sequenceId,
                'conversation_id' => $this->conversationId,
                'expected_cycle' => $this->expectedCycle,
                'current_cycle'  => $currentCycle,
            ]);
            return;
        }

        // --- All guards passed: enroll ---
        DB::transaction(function () use ($sequence, $conversation, $enrollmentService) {

            // Stop any existing active enrollment for this sequence+conversation
            // (from a previous cycle that completed or is still running).
            $existing = SequenceEnrollment::where('sequence_id', $this->sequenceId)
                ->where('conversation_id', $this->conversationId)
                ->where('status', 'active')
                ->first();

            if ($existing) {
                $existing->stop('new_no_reply_cycle');
            }

            // Check allow_reentry for non-active past enrollments.
            $settings     = $sequence->settings ?? [];
            $allowReentry = $settings['allow_reentry'] ?? true; // no_reply defaults to allow reentry

            if (!$allowReentry) {
                $pastEnrollment = SequenceEnrollment::where('sequence_id', $this->sequenceId)
                    ->where('conversation_id', $this->conversationId)
                    ->whereIn('status', ['completed', 'stopped'])
                    ->exists();

                if ($pastEnrollment) {
                    Log::info('CheckNoReplyForMessage: reentry disabled, conversation already enrolled before', [
                        'sequence_id'     => $this->sequenceId,
                        'conversation_id' => $this->conversationId,
                    ]);
                    return;
                }
            }

            // Create the new enrollment, tagged with this cycle number and the
            // triggering messages so future jobs can use them for the gate check.
            $enrollment = SequenceEnrollment::create([
                'sequence_id'        => $this->sequenceId,
                'conversation_id'    => $this->conversationId,
                'status'             => 'active',
                'current_step'       => 1,
                'started_at'         => now(),
                'trigger_ai_reply_id' => $this->aiReplyMessageId,
                'no_reply_cycle'     => $this->expectedCycle,
                'metadata'           => [
                    'trigger'         => 'no_reply',
                    'ai_reply_id'     => $this->aiReplyMessageId,
                    'cycle'           => $this->expectedCycle,
                ],
            ]);

            Log::info('CheckNoReplyForMessage: enrolled conversation in no-reply sequence', [
                'sequence_id'     => $this->sequenceId,
                'conversation_id' => $this->conversationId,
                'enrollment_id'   => $enrollment->id,
                'cycle'           => $this->expectedCycle,
                'ai_reply_id'     => $this->aiReplyMessageId,
            ]);

            // Kick off the first step immediately via the existing execution service.
            $enrollmentService->queueStepExecution($enrollment);
        });
    }

    public function failed(\Throwable $e): void
    {
        Log::error('CheckNoReplyForMessage: job failed', [
            'sequence_id'     => $this->sequenceId,
            'conversation_id' => $this->conversationId,
            'ai_reply_id'     => $this->aiReplyMessageId,
            'error'           => $e->getMessage(),
        ]);
    }
}
