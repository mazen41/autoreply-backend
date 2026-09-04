<?php

namespace App\Services;

use App\Models\Sequence;
use App\Models\Conversation;
use App\Models\SequenceEnrollment;
use App\Services\SequenceEnrollmentService;
use Illuminate\Support\Facades\Log;

class SequenceTriggerService
{
    private SequenceEnrollmentService $enrollmentService;

    public function __construct(SequenceEnrollmentService $enrollmentService)
    {
        $this->enrollmentService = $enrollmentService;
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

        // Find active sequences with manual or new_user triggers
        $autoEnrollSequences = Sequence::forBusiness($businessId)
            ->active()
            ->whereIn('trigger_type', ['new_user', 'manual'])
            ->get();

        foreach ($autoEnrollSequences as $sequence) {
            try {
                $this->enrollIfEligible($sequence, $conversation);
            } catch (\Exception $e) {
                Log::error('Failed to auto-enroll conversation in sequence', [
                    'sequence_id' => $sequence->id,
                    'conversation_id' => $conversation->id,
                    'error' => $e->getMessage(),
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
                'error' => $e->getMessage(),
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

        // Find active sequences with tag_added trigger matching this tag
        $tagSequences = Sequence::forBusiness($businessId)
            ->active()
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
                    'tag' => $tag,
                    'error' => $e->getMessage(),
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

        // Find active sequences with no_reply trigger
        $noReplySequences = Sequence::forBusiness($businessId)
            ->active()
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
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Check and enroll for order_created trigger (from Salla webhook)
     */
    public function checkAndEnrollForOrderCreated(Conversation $conversation, array $orderData): void
    {
        $businessId = $conversation->business_id;
        
        if (!$businessId) {
            return;
        }

        // Find active sequences with order_created trigger
        $orderSequences = Sequence::forBusiness($businessId)
            ->active()
            ->where('trigger_type', 'order_created')
            ->get();

        foreach ($orderSequences as $sequence) {
            try {
                $triggerConfig = $sequence->trigger_config ?? [];
                
                // Check if order value meets minimum threshold
                $minOrderValue = $triggerConfig['min_order_value'] ?? 0;
                $orderValue = $orderData['total'] ?? 0;
                
                if ($orderValue >= $minOrderValue) {
                    $this->enrollIfEligible($sequence, $conversation);
                }
            } catch (\Exception $e) {
                Log::error('Failed to enroll conversation in order-triggered sequence', [
                    'sequence_id' => $sequence->id,
                    'conversation_id' => $conversation->id,
                    'order_id' => $orderData['id'] ?? null,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Check eligibility and enroll if conditions are met
     */
    private function enrollIfEligible(Sequence $sequence, Conversation $conversation): void
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
        $this->enrollmentService->enrollConversation($sequence, $conversation);
        
        Log::info('Auto-enrolled conversation in sequence', [
            'sequence_id' => $sequence->id,
            'sequence_name' => $sequence->name,
            'conversation_id' => $conversation->id,
        ]);
    }
}
