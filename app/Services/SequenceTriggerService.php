<?php

namespace App\Services;

use App\Jobs\CheckNoReplyForMessage;
use App\Models\Message;
use App\Models\Sequence;
use App\Models\Conversation;
use App\Models\SequenceEnrollment;
use App\Services\SequenceEnrollmentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SequenceTriggerService
{
    private SequenceEnrollmentService $enrollmentService;

    public function __construct(SequenceEnrollmentService $enrollmentService)
    {
        $this->enrollmentService = $enrollmentService;
    }

    /**
     * Schedule no-reply timers after the AI sends a reply.
     *
     * Called from ProcessAutoReply immediately after the outbound AI message
     * is saved and sent. Dispatches a delayed CheckNoReplyForMessage job for
     * every active no-reply sequence configured for this business.
     *
     * The delay is derived from the sequence's trigger_config (supports both
     * 'minutes' and 'hours' units). Nothing is hardcoded.
     *
     * The job will verify at execution time that the customer has NOT replied
     * since $aiReplyMessage was sent. If they have, the job exits silently.
     * This is the idempotency / "cancel stale timer" gate.
     */
    public function scheduleNoReplyTimers(Conversation $conversation, Message $aiReplyMessage): void
    {
        $businessId = $conversation->business_id;
        if (!$businessId) {
            return;
        }

        // Bot scope: global sequences plus the conversation's bot's sequences.
        $noReplySequences = Sequence::forBusiness($businessId)
            ->active()
            ->forBotScope($conversation->bot_id ? (int) $conversation->bot_id : null)
            ->where('trigger_type', 'no_reply')
            ->get();

        if ($noReplySequences->isEmpty()) {
            return;
        }

        foreach ($noReplySequences as $sequence) {
            try {
                $triggerConfig = $sequence->trigger_config ?? [];

                // ── Resolve delay seconds from trigger_config ────────────────
                //
                // NEW format (stored by fixed frontend):
                //   { delay_value: 3, delay_unit: 'minutes' }
                //
                // LEGACY format (old frontend stored fractional hours):
                //   { hours: 0.05 }  ← 3 minutes stored as 3/60
                //   (int)0.05 === 0, so old code always produced delay_seconds=0)
                //
                // Both formats are handled here without any (int) cast on the
                // raw value so fractional legacy values still work.

                if (isset($triggerConfig['delay_value']) && isset($triggerConfig['delay_unit'])) {
                    // New format — delay_value is always a whole number
                    $delayValue = (float) $triggerConfig['delay_value'];
                    $delayUnit  = (string) $triggerConfig['delay_unit'];
                } elseif (isset($triggerConfig['hours'])) {
                    // Legacy format — convert fractional hours directly to seconds
                    // Skip the intermediate (int) cast that was dropping fractions.
                    $delayValue = (float) $triggerConfig['hours'];
                    $delayUnit  = 'hours';
                } else {
                    // No config at all — default to 60 minutes
                    $delayValue = 60;
                    $delayUnit  = 'minutes';
                }

                $delaySeconds = (int) round(match ($delayUnit) {
                    'minutes' => $delayValue * 60,
                    'hours'   => $delayValue * 3600,
                    'days'    => $delayValue * 86400,
                    default   => $delayValue * 60,
                });

                if ($delaySeconds <= 0) {
                    Log::warning('SequenceTriggerService: computed delay_seconds is zero or negative — using 60s fallback', [
                        'sequence_id'  => $sequence->id,
                        'trigger_config' => $triggerConfig,
                        'delay_value'  => $delayValue,
                        'delay_unit'   => $delayUnit,
                    ]);
                    $delaySeconds = 60; // 1-minute safety floor
                }

                // Persist the cycle before dispatch. Enrollment rows do not
                // exist until the timer fires, so they cannot safely gate newer
                // timers on their own.
                $newCycle = DB::transaction(function () use ($sequence, $conversation, $aiReplyMessage) {
                    DB::table('sequence_no_reply_cycles')->insertOrIgnore([
                        'sequence_id' => $sequence->id,
                        'conversation_id' => $conversation->id,
                        'cycle' => 0,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    $state = DB::table('sequence_no_reply_cycles')
                        ->where('sequence_id', $sequence->id)
                        ->where('conversation_id', $conversation->id)
                        ->lockForUpdate()
                        ->first();
                    $cycle = (int) $state->cycle + 1;

                    DB::table('sequence_no_reply_cycles')
                        ->where('id', $state->id)
                        ->update([
                            'cycle' => $cycle,
                            'ai_reply_message_id' => $aiReplyMessage->id,
                            'processed_at' => null,
                            'updated_at' => now(),
                        ]);

                    return $cycle;
                });

                Log::info('SequenceTriggerService: scheduling no-reply timer', [
                    'sequence_id'     => $sequence->id,
                    'conversation_id' => $conversation->id,
                    'ai_reply_id'     => $aiReplyMessage->id,
                    'delay_value'     => $delayValue,
                    'delay_unit'      => $delayUnit,
                    'delay_seconds'   => $delaySeconds,
                    'fires_at'        => now()->addSeconds($delaySeconds)->toISOString(),
                    'cycle'           => $newCycle,
                ]);

                CheckNoReplyForMessage::dispatch(
                    $sequence->id,
                    $conversation->id,
                    $aiReplyMessage->id,
                    $newCycle,
                )->delay($delaySeconds)->afterCommit();

            } catch (\Exception $e) {
                Log::error('SequenceTriggerService: failed to schedule no-reply timer', [
                    'sequence_id'     => $sequence->id,
                    'conversation_id' => $conversation->id,
                    'exception_type' => get_class($e),
                ]);
            }
        }
    }

    /**
     * When the customer sends a new inbound message, the previous no-reply
     * timers are automatically invalidated by the cycle-number gate in
     * CheckNoReplyForMessage. No explicit cancellation is needed here.
     *
     * However we DO stop any ACTIVE no-reply sequence enrollments so the
     * running sequence steps don't continue while the customer is talking.
     */
    public function cancelNoReplySequencesOnCustomerReply(Conversation $conversation): void
    {
        $enrollmentService = app(SequenceEnrollmentService::class);
        $stopped = SequenceEnrollment::where('conversation_id', $conversation->id)
            ->where('status', 'active')
            ->with('sequence', 'executions')
            ->get();

        foreach ($stopped as $enrollment) {
            $sequence = $enrollment->sequence;
            $stopOnReply = $sequence?->trigger_type === 'no_reply'
                || (bool) data_get($sequence?->settings, 'stop_on_reply', false);
            if (!$stopOnReply) {
                continue;
            }

            $enrollmentService->unenrollConversation($enrollment, 'customer_replied');
            Log::info('SequenceTriggerService: stopped configured sequence on customer reply', [
                'enrollment_id'   => $enrollment->id,
                'sequence_id'     => $enrollment->sequence_id,
                'conversation_id' => $conversation->id,
            ]);
        }
    }

    /**
     * Check if a conversation should be enrolled in any active sequences
     * This is called from ProcessAutoReply after a message is received
     */
    public function checkAndEnrollForMessageReceived(Conversation $conversation): void
    {
        $businessId = $conversation->business_id;
        
        if (!$businessId) {
            return;
        }

        // Manual sequences are started only by an explicit manual enrollment.
        // A new_user sequence is eligible only for the conversation's first
        // inbound message.
        if ($conversation->messages()->where('direction', 'inbound')->count() !== 1) {
            return;
        }

        $autoEnrollSequences = Sequence::forBusiness($businessId)
            ->active()
            ->forBotScope($conversation->bot_id ? (int) $conversation->bot_id : null)
            ->where('trigger_type', 'new_user')
            ->get();

        foreach ($autoEnrollSequences as $sequence) {
            try {
                $this->enrollIfEligible($sequence, $conversation);
            } catch (\Exception $e) {
                Log::error('Failed to auto-enroll conversation in sequence', [
                    'sequence_id' => $sequence->id,
                    'conversation_id' => $conversation->id,
                    'exception_type' => get_class($e),
                ]);
            }
        }
    }

    /**
     * Enroll a conversation in a specific sequence if eligible
     */
    public function enrollInSequence(Sequence $sequence, Conversation $conversation, bool $checkDuplicates = true): ?SequenceEnrollment
    {
        if (!$this->enrollmentService->canEnroll($sequence, $conversation)) {
            return null;
        }

        try {
            if ($checkDuplicates) {
                return $this->enrollmentService->enrollConversation($sequence, $conversation);
            } else {
                return $this->enrollmentService->enrollConversationWithoutDuplicateCheck($sequence, $conversation);
            }
        } catch (\Exception $e) {
            Log::error('Failed to enroll conversation in sequence', [
                'sequence_id' => $sequence->id,
                'conversation_id' => $conversation->id,
                'exception_type' => get_class($e),
            ]);
            throw $e;
        }
    }

    /**
     * Stop all active sequences for a conversation when customer replies
     */
    public function stopSequencesOnCustomerReply(Conversation $conversation): void
    {
        $enrollmentService = app(SequenceEnrollmentService::class);
        $count = $enrollmentService->stopEnrollmentsForConversation($conversation, 'customer_replied');
        
        if ($count > 0) {
            Log::info('Stopped sequences due to customer reply', [
                'conversation_id' => $conversation->id,
                'count' => $count,
            ]);
        }
    }

    /**
     * Check if a conversation should be enrolled based on tag added
     */
    public function checkAndEnrollForTagAdded(Conversation $conversation, string $tag): void
    {
        $businessId = $conversation->business_id;
        
        if (!$businessId) {
            return;
        }

        // Find active sequences with tag_added trigger matching this tag,
        // scoped to the conversation's bot (global sequences always eligible).
        $tagSequences = Sequence::forBusiness($businessId)
            ->active()
            ->forBotScope($conversation->bot_id ? (int) $conversation->bot_id : null)
            ->where('trigger_type', 'tag_added')
            ->whereJsonContains('trigger_config->tags', $tag)
            ->get();

        foreach ($tagSequences as $sequence) {
            try {
                $this->enrollIfEligible($sequence, $conversation);
            } catch (\Exception $e) {
                Log::error('Failed to enroll conversation in tag-triggered sequence', [
                    'sequence_id' => $sequence->id,
                    'conversation_id' => $conversation->id,
                    'exception_type' => get_class($e),
                ]);
            }
        }
    }

    /**
     * Check and enroll for no_reply trigger (handled by scheduled job)
     */
    public function checkAndEnrollForNoReply(Conversation $conversation): void
    {
        $businessId = $conversation->business_id;
        
        if (!$businessId) {
            return;
        }

        // Find active sequences with no_reply trigger, scoped to the
        // conversation's bot (global sequences always eligible).
        $noReplySequences = Sequence::forBusiness($businessId)
            ->active()
            ->forBotScope($conversation->bot_id ? (int) $conversation->bot_id : null)
            ->where('trigger_type', 'no_reply')
            ->get();

        foreach ($noReplySequences as $sequence) {
            try {
                $triggerConfig = $sequence->trigger_config ?? [];
                $hoursWithoutReply = $triggerConfig['hours'] ?? 24;

                // Check if the last message was from business (outbound)
                // and sent more than the configured hours ago (customer hasn't replied)
                $threshold = now()->subHours($hoursWithoutReply);
                
                $lastMessage = $conversation->messages()
                    ->latest()
                    ->first();

                if ($lastMessage && 
                    $lastMessage->direction === 'outbound' && 
                    $lastMessage->created_at->lte($threshold)) {
                    $this->enrollIfEligible($sequence, $conversation);
                }
            } catch (\Exception $e) {
                Log::error('Failed to enroll conversation in no-reply sequence', [
                    'sequence_id' => $sequence->id,
                    'conversation_id' => $conversation->id,
                    'exception_type' => get_class($e),
                ]);
            }
        }
    }

    /**
     * Check and enroll for order_created trigger (from Salla webhook or AI order creation)
     */
    public function checkAndEnrollForOrderCreated(Conversation $conversation, array $orderData): void
    {
        // business_id on Conversation is nullable (old rows pre-migration).
        // Fall back to channel->business_id which is always set.
        $businessId = $conversation->business_id
            ?? optional($conversation->channel)->business_id
            ?? null;
        
        if (!$businessId) {
            Log::warning('SequenceTriggerService: checkAndEnrollForOrderCreated — no business_id resolvable', [
                'conversation_id' => $conversation->id,
                'order_id'        => $orderData['id'] ?? $orderData['order_id'] ?? null,
            ]);
            return;
        }

        Log::info('SequenceTriggerService: checkAndEnrollForOrderCreated', [
            'conversation_id' => $conversation->id,
            'business_id'     => $businessId,
            'order_id'        => $orderData['id'] ?? $orderData['order_id'] ?? null,
        ]);

        // Find active sequences with order_created trigger, scoped to the
        // conversation's bot (global sequences always eligible).
        $orderSequences = Sequence::forBusiness($businessId)
            ->active()
            ->forBotScope($conversation->bot_id ? (int) $conversation->bot_id : null)
            ->where('trigger_type', 'order_created')
            ->get();

        foreach ($orderSequences as $sequence) {
            try {
                $triggerConfig = $sequence->trigger_config ?? [];
                
                // Check if order value meets minimum threshold
                $minOrderValue = $triggerConfig['min_order_value'] ?? 0;
                $orderValue = $orderData['total'] ?? 0;
                
                if ($orderValue >= $minOrderValue) {
                    $this->enrollIfEligible($sequence, $conversation, [
                        'order_data' => $orderData,
                        'trigger_order_id' => $orderData['id'] ?? $orderData['order_id'] ?? null,
                        'enrolled_by' => 'order_created',
                    ]);
                }
            } catch (\Exception $e) {
                Log::error('Failed to enroll conversation in order-triggered sequence', [
                    'sequence_id' => $sequence->id,
                    'conversation_id' => $conversation->id,
                    'exception_type' => get_class($e),
                    'validation_errors' => $e instanceof \Illuminate\Validation\ValidationException
                        ? $e->errors()
                        : [],
                ]);
            }
        }
    }

    /**
     * Check eligibility and enroll if conditions are met
     */
    private function enrollIfEligible(Sequence $sequence, Conversation $conversation, array $extraMetadata = []): void
    {
        // Check if already enrolled
        $existingEnrollment = SequenceEnrollment::forSequence($sequence->id)
            ->forConversation($conversation->id)
            ->active()
            ->first();

        if ($existingEnrollment) {
            return;
        }

        // Check sequence settings for eligibility
        $settings = $sequence->settings ?? [];
        $allowReentry = $settings['allow_reentry'] ?? false;

        if (!$allowReentry) {
            // Check if conversation was ever enrolled in this sequence
            $pastEnrollment = SequenceEnrollment::forSequence($sequence->id)
                ->forConversation($conversation->id)
                ->where('status', '!=', 'active')
                ->first();

            if ($pastEnrollment) {
                return;
            }
        }

        // Enroll the conversation
        $this->enrollmentService->enrollConversation($sequence, $conversation, 1, $extraMetadata);
        
        Log::info('Auto-enrolled conversation in sequence', [
            'sequence_id' => $sequence->id,
            'sequence_name' => $sequence->name,
            'conversation_id' => $conversation->id,
        ]);
    }
}
