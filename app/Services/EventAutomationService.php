<?php

namespace App\Services;

use App\Models\Sequence;
use App\Models\SequenceUser;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Webhook;
use App\Services\WebhookService;
use Illuminate\Support\Facades\Log;

class EventAutomationService
{
    private $webhookService;

    public function __construct(WebhookService $webhookService)
    {
        $this->webhookService = $webhookService;
    }

    /**
     * Handle new message event
     */
    public function handleNewMessage(Message $message): void
    {
        $conversation = $message->conversation;
        $business = $conversation->business;

        if (!$business) {
            return;
        }

        // Trigger webhooks
        $this->webhookService->triggerEvent($business->id, 'new_message', [
            'message_id' => $message->id,
            'conversation_id' => $conversation->id,
            'sender_id' => $conversation->sender_id,
            'content' => $message->content,
            'is_ai' => $message->is_ai,
        ]);

        // Check for sequence triggers
        $this->checkSequenceTriggers($business, $conversation, 'new_message');

        Log::info('New message event handled', [
            'message_id' => $message->id,
            'business_id' => $business->id,
        ]);
    }

    /**
     * Handle conversation escalation event
     */
    public function handleEscalation(Conversation $conversation, string $reason): void
    {
        $business = $conversation->business;

        if (!$business) {
            return;
        }

        // Trigger webhooks
        $this->webhookService->triggerEvent($business->id, 'escalation', [
            'conversation_id' => $conversation->id,
            'reason' => $reason,
            'escalated_at' => now()->toISOString(),
        ]);

        // Notify team members
        $notificationService = new \App\Services\NotificationService();
        $teamMembers = $business->teamMembers()->where('role', '!=', 'viewer')->get();

        foreach ($teamMembers as $member) {
            $notificationService->escalation(
                $member->user_id,
                $reason,
                $conversation->id
            );
        }

        Log::info('Escalation event handled', [
            'conversation_id' => $conversation->id,
            'reason' => $reason,
        ]);
    }

    /**
     * Handle tag added event
     */
    public function handleTagAdded(Conversation $conversation, string $tag): void
    {
        $business = $conversation->business;

        if (!$business) {
            return;
        }

        // Trigger webhooks
        $this->webhookService->triggerEvent($business->id, 'tag_added', [
            'conversation_id' => $conversation->id,
            'tag' => $tag,
        ]);

        // Check for sequence triggers
        $this->checkSequenceTriggers($business, $conversation, 'tag_added', ['tag' => $tag]);

        Log::info('Tag added event handled', [
            'conversation_id' => $conversation->id,
            'tag' => $tag,
        ]);
    }

    /**
     * Handle new user event
     */
    public function handleNewUser(Conversation $conversation): void
    {
        $business = $conversation->business;

        if (!$business) {
            return;
        }

        // Trigger webhooks
        $this->webhookService->triggerEvent($business->id, 'new_user', [
            'conversation_id' => $conversation->id,
            'sender_id' => $conversation->sender_id,
            'created_at' => $conversation->created_at->toISOString(),
        ]);

        // Check for sequence triggers
        $this->checkSequenceTriggers($business, $conversation, 'new_user');

        Log::info('New user event handled', [
            'conversation_id' => $conversation->id,
            'sender_id' => $conversation->sender_id,
        ]);
    }

    /**
     * Handle payment received event
     */
    public function handlePaymentReceived(int $businessId, array $paymentData): void
    {
        // Trigger webhooks
        $this->webhookService->triggerEvent($businessId, 'payment_received', $paymentData);

        Log::info('Payment received event handled', [
            'business_id' => $businessId,
            'amount' => $paymentData['amount'] ?? null,
        ]);
    }

    /**
     * Handle order status change event
     */
    public function handleOrderStatusChange(int $businessId, string $orderId, string $status): void
    {
        // Trigger webhooks
        $this->webhookService->triggerEvent($businessId, 'order_status_change', [
            'order_id' => $orderId,
            'status' => $status,
            'updated_at' => now()->toISOString(),
        ]);

        Log::info('Order status change event handled', [
            'business_id' => $businessId,
            'order_id' => $orderId,
            'status' => $status,
        ]);
    }

    /**
     * Check for sequence triggers
     */
    private function checkSequenceTriggers($business, $conversation, string $eventType, array $context = []): void
    {
        $sequences = Sequence::where('business_id', $business->id)
            ->where('trigger_type', $eventType)
            ->where('is_active', true)
            ->get();

        foreach ($sequences as $sequence) {
            // Check trigger config if exists
            if ($sequence->trigger_config) {
                $matches = $this->evaluateTriggerConfig($sequence->trigger_config, $context);
                if (!$matches) {
                    continue;
                }
            }

            // Enroll conversation in sequence
            $existingEnrollment = SequenceUser::where('sequence_id', $sequence->id)
                ->where('conversation_id', $conversation->id)
                ->first();

            if (!$existingEnrollment) {
                SequenceUser::create([
                    'sequence_id' => $sequence->id,
                    'conversation_id' => $conversation->id,
                    'current_step' => 0,
                    'status' => 'active',
                    'started_at' => now(),
                ]);

                // Trigger first step
                \App\Jobs\ProcessSequenceStep::dispatch(
                    SequenceUser::where('sequence_id', $sequence->id)
                        ->where('conversation_id', $conversation->id)
                        ->first()->id
                );

                Log::info('Conversation enrolled in sequence', [
                    'sequence_id' => $sequence->id,
                    'conversation_id' => $conversation->id,
                ]);
            }
        }
    }

    /**
     * Evaluate trigger configuration
     */
    private function evaluateTriggerConfig(array $config, array $context): bool
    {
        // Simple evaluation - can be expanded
        if (isset($config['tag']) && isset($context['tag'])) {
            return $config['tag'] === $context['tag'];
        }

        return true;
    }

    /**
     * Handle no reply event (after X hours of inactivity)
     */
    public function handleNoReply(Conversation $conversation, int $hours = 24): void
    {
        $business = $conversation->business;

        if (!$business) {
            return;
        }

        $lastMessage = $conversation->messages()
            ->where('direction', 'inbound')
            ->orderBy('created_at', 'desc')
            ->first();

        if (!$lastMessage) {
            return;
        }

        // Check if last inbound message was X hours ago
        if ($lastMessage->created_at->lt(now()->subHours($hours))) {
            // Trigger webhooks
            $this->webhookService->triggerEvent($business->id, 'no_reply', [
                'conversation_id' => $conversation->id,
                'last_message_at' => $lastMessage->created_at->toISOString(),
                'hours_since_last_message' => $hours,
            ]);

            // Check for sequence triggers
            $this->checkSequenceTriggers($business, $conversation, 'no_reply');

            Log::info('No reply event handled', [
                'conversation_id' => $conversation->id,
                'hours' => $hours,
            ]);
        }
    }
}
