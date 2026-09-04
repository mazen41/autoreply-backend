<?php

namespace App\Services;

use App\Models\SequenceStepExecution;
use App\Models\SequenceEnrollment;
use App\Models\SequenceStep;
use App\Models\Message;
use App\Models\Conversation;
use App\Models\Channel;
use App\Services\EvolutionApiService;
use App\Services\BusinessHoursService;
use App\Jobs\ExecuteSequenceStep;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SequenceExecutionService
{
    protected BusinessHoursService $businessHoursService;

    public function __construct(BusinessHoursService $businessHoursService)
    {
        $this->businessHoursService = $businessHoursService;
    }

    public function executeStep(SequenceStepExecution $execution, SequenceEnrollment $enrollment, SequenceStep $step): void
    {
        // Check if step was already executed (idempotency)
        if ($execution->status === 'executed') {
            Log::info("Sequence step already executed, skipping", [
                'execution_id' => $execution->id,
                'sequence_enrollment_id' => $enrollment->id,
                'step_id' => $step->id,
            ]);
            return;
        }

        $execution->status = 'executed';
        $execution->executed_at = now();

        switch ($step->step_type) {
            case 'message':
                $this->executeMessageStep($execution, $enrollment, $step);
                // After message, move to next step
                $this->moveToNextStep($enrollment);
                break;
            case 'delay':
                $this->executeDelayStep($execution, $enrollment, $step);
                // Delay step should schedule the next step with the delay
                $this->scheduleNextStepAfterDelay($enrollment, $step);
                break;
            case 'condition':
                $this->executeConditionStep($execution, $enrollment, $step);
                // Only move to next step if condition passed
                if (!$execution->metadata['condition_result'] ?? true) {
                    $this->moveToNextStep($enrollment);
                }
                break;
            case 'action':
                $this->executeActionStep($execution, $enrollment, $step);
                // Only move to next step if action didn't stop sequence
                if (!($execution->metadata['action_type'] ?? '') === 'stop_sequence') {
                    $this->moveToNextStep($enrollment);
                }
                break;
            default:
                $execution->markAsSkipped('unknown_step_type');
        }

        $execution->save();
    }

    protected function executeMessageStep(SequenceStepExecution $execution, SequenceEnrollment $enrollment, SequenceStep $step): void
    {
        $conversation = $enrollment->conversation;
        $sequence = $enrollment->sequence;

        if (!$conversation) {
            throw new \Exception('Conversation not found');
        }

        // Get channel for sending.
        // IMPORTANT: order by most-recently-connected first. This is defense
        // in depth against stale duplicate Channel rows (e.g. an old
        // disconnected WhatsApp instance's channel that didn't get its status
        // flipped in time) — even though EvolutionApiService now actively
        // marks stale channels disconnected on reconnect/close, an explicit
        // order here means we never silently pick the wrong one if that sync
        // is ever delayed or missed.
        $channel = $sequence->channel ? Channel::where('business_id', $sequence->business_id)
            ->where('type', $sequence->channel)
            ->where('status', 'connected')
            ->orderByDesc('connected_at')
            ->first() : $conversation->channel;

        // Even if $conversation->channel resolved to something, it may be
        // stale (e.g. the conversation was created against an instance that
        // has since disconnected and been replaced). Re-verify it's actually
        // still connected, and if not, fall back to the business's current
        // live channel of the same type rather than sending into a dead session.
        if ($channel && $channel->status !== 'connected') {
            Log::warning('SequenceExecutionService: resolved channel is not connected, looking for a live replacement', [
                'stale_channel_id' => $channel->id,
                'business_id'      => $sequence->business_id,
                'channel_type'     => $channel->type,
            ]);

            $channel = Channel::where('business_id', $sequence->business_id)
                ->where('type', $channel->type)
                ->where('status', 'connected')
                ->orderByDesc('connected_at')
                ->first();
        }

        if (!$channel) {
            throw new \Exception('No connected channel found for sending message');
        }

        // Send message based on channel type
        $message = $this->sendMessageToConversation($conversation, $step->message, $channel, $step->config ?? []);

        if ($message) {
            $execution->message_id = $message->id;
        }
    }

    protected function executeDelayStep(SequenceStepExecution $execution, SequenceEnrollment $enrollment, SequenceStep $step): void
    {
        // Delay steps are handled by the job scheduling system
        // This is just a marker that the delay has been "executed"
        $execution->metadata = array_merge($execution->metadata ?? [], [
            'delay_completed' => true,
            'delay_hours' => $step->delay_hours,
            'delay_unit' => $step->delay_unit,
        ]);
        
        // CRITICAL FIX: Delay steps should NOT immediately move to next step
        // Instead, the delay should be applied when scheduling the NEXT step
        // The delay step itself is just a marker and completes immediately
        // But we need to schedule the next step with the delay
        
        Log::info("Delay step executed, scheduling next step with delay", [
            'execution_id' => $execution->id,
            'enrollment_id' => $enrollment->id,
            'step_id' => $step->id,
            'delay_hours' => $step->delay_hours,
            'delay_unit' => $step->delay_unit,
        ]);
        
        // Don't call moveToNextStep here - handle it specially
        // The next step should be scheduled after the delay period
    }

    protected function executeConditionStep(SequenceStepExecution $execution, SequenceEnrollment $enrollment, SequenceStep $step): void
    {
        $conditionResult = $this->evaluateCondition($enrollment, $step->condition_config ?? []);

        $execution->metadata = array_merge($execution->metadata ?? [], [
            'condition_result' => $conditionResult,
            'condition_config' => $step->condition_config,
        ]);

        // If condition fails, stop the sequence
        if (!$conditionResult) {
            $enrollment->stop('condition_failed');
            $execution->markAsSkipped('condition_failed');
        }
    }

    protected function executeActionStep(SequenceStepExecution $execution, SequenceEnrollment $enrollment, SequenceStep $step): void
    {
        $actionType = $step->config['action_type'] ?? null;

        switch ($actionType) {
            case 'stop_sequence':
                $enrollment->stop('action_stop');
                $execution->markAsSkipped('action_stop');
                break;
            case 'add_tag':
                // Add tag to conversation
                $this->addTagToConversation($enrollment->conversation, $step->config['tag'] ?? null);
                break;
            case 'remove_tag':
                // Remove tag from conversation
                $this->removeTagFromConversation($enrollment->conversation, $step->config['tag'] ?? null);
                break;
            default:
                $execution->markAsSkipped('unknown_action_type');
        }
    }

    protected function sendMessageToConversation(Conversation $conversation, string $message, Channel $channel, array $config = []): ?Message
    {
        $senderId = $conversation->sender_id;
        $senderName = $conversation->sender_name;

        // Create message record with delivery tracking
        $messageRecord = Message::create([
            'conversation_id' => $conversation->id,
            'content' => $message,
            'type' => 'text',
            'direction' => 'outbound',
            'status' => 'sent',
            'is_ai' => true,
            'source' => 'sequence',
            'delivery_status' => 'pending',
            'metadata' => array_merge($config, [
                'sequence_automated' => true,
                'channel_type' => $channel->type,
            ]),
        ]);

        // Send based on channel type
        try {
            switch ($channel->type) {
                case 'whatsapp':
                    $this->sendWhatsAppMessage($conversation, $message, $channel, $messageRecord);
                    break;
                case 'telegram':
                    $this->sendTelegramMessage($conversation, $message, $channel, $messageRecord);
                    break;
                case 'email':
                    $this->sendEmailMessage($conversation, $message, $channel, $messageRecord);
                    break;
                default:
                    Log::warning("Unsupported channel type for sequence: {$channel->type}");
                    throw new \Exception("Channel type {$channel->type} is not supported");
            }
        } catch (\Exception $e) {
            Log::error("Failed to send sequence message", [
                'message_id' => $messageRecord->id,
                'channel_type' => $channel->type,
                'error' => $e->getMessage(),
            ]);
            $messageRecord->update([
                'delivery_status' => 'failed',
                'error_details' => $e->getMessage(),
            ]);
            throw $e; // Re-throw to trigger retry logic
        }

        return $messageRecord;
    }

    protected function sendWhatsAppMessage(Conversation $conversation, string $message, Channel $channel, Message $messageRecord): void
    {
        $evolutionService = new EvolutionApiService();

        // Send via Evolution API. A successful (200) response only confirms
        // Evolution's REST layer accepted the request into its send queue —
        // it does NOT confirm the message actually reached WhatsApp, since
        // that depends on the underlying session (Baileys/WhatsApp Web) still
        // being alive. Real delivery confirmation arrives later via the
        // messages.update webhook (DELIVERY_ACK / READ status), the same way
        // it's tracked for normal AI replies elsewhere in the app.
        //
        // Marking this 'delivered' immediately, before that webhook arrives,
        // is what let a message look successfully sent in our own logs/dashboard
        // while never actually reaching the customer's phone (the channel had
        // silently disconnected). Mark it 'sent'/'pending' instead, and let the
        // existing messages.update webhook handler update it to the real status
        // once WhatsApp actually confirms delivery.
        $evolutionService->sendTextMessage(
            $channel->page_id, // instance name
            $conversation->sender_id,
            $message
        );

        $messageRecord->update([
            'whatsapp_message_id' => $messageRecord->id, // Use message ID as reference
            'send_status' => 'sent',
            'delivery_status' => 'pending',
        ]);
    }

    protected function sendTelegramMessage(Conversation $conversation, string $message, Channel $channel, Message $messageRecord): void
    {
        // Use existing TelegramService
        $telegramService = new TelegramService();
        
        // Extract bot token from channel metadata or config
        $botToken = $channel->metadata['bot_token'] ?? null;
        
        if (!$botToken) {
            Log::error("Telegram bot token not found in channel metadata", [
                'channel_id' => $channel->id,
            ]);
            throw new \Exception('Telegram bot token not configured');
        }

        $success = $telegramService->sendMessage($botToken, $conversation->sender_id, $message);

        if (!$success) {
            throw new \Exception('Failed to send Telegram message');
        }

        $messageRecord->update([
            'send_status' => 'sent',
            'delivery_status' => 'delivered',
        ]);
    }

    protected function sendEmailMessage(Conversation $conversation, string $message, Channel $channel, Message $messageRecord): void
    {
        // Use Laravel Mail facade for email sending
        $recipientEmail = $conversation->sender_email ?? $conversation->sender_id;
        
        if (!$recipientEmail) {
            throw new \Exception('No email address found for conversation');
        }

        \Illuminate\Support\Facades\Mail::raw($message, function ($mail) use ($recipientEmail) {
            $mail->to($recipientEmail)
                ->subject('Automated Message')
                ->from(config('mail.from.address'), config('mail.from.name'));
        });

        $messageRecord->update([
            'send_status' => 'sent',
            'delivery_status' => 'delivered',
        ]);
    }

    protected function evaluateCondition(SequenceEnrollment $enrollment, array $conditionConfig): bool
    {
        $conditionType = $conditionConfig['type'] ?? null;

        switch ($conditionType) {
            case 'customer_replied':
                return $this->checkCustomerReplied($enrollment);
            case 'has_tag':
                return $this->checkHasTag($enrollment, $conditionConfig['tag'] ?? null);
            case 'time_based':
                return $this->checkTimeCondition($conditionConfig);
            case 'message_delivered':
                return $this->checkMessageDelivered($enrollment, $conditionConfig['message_id'] ?? null);
            default:
                return true; // Default to true if condition type unknown
        }
    }

    protected function checkCustomerReplied(SequenceEnrollment $enrollment): bool
    {
        $conversation = $enrollment->conversation;
        
        // Check if customer has sent a message since enrollment started
        return $conversation->messages()
            ->where('direction', 'inbound')
            ->where('created_at', '>=', $enrollment->started_at)
            ->exists();
    }

    protected function checkHasTag(SequenceEnrollment $enrollment, ?string $tag): bool
    {
        if (!$tag) return false;

        $conversation = $enrollment->conversation;
        
        return $conversation->tags()->where('tag', $tag)->exists();
    }

    protected function checkTimeCondition(array $conditionConfig): bool
    {
        $currentTime = now();
        $conditionTime = $conditionConfig['time'] ?? null;

        if (!$conditionTime) return true;

        // Parse condition time and compare
        try {
            $targetTime = \Carbon\Carbon::parse($conditionTime);
            return $currentTime->gte($targetTime);
        } catch (\Exception $e) {
            Log::error("Failed to parse time condition: {$e->getMessage()}");
            return true;
        }
    }

    protected function checkMessageDelivered(SequenceEnrollment $enrollment, ?int $messageId): bool
    {
        if (!$messageId) return false;

        $message = Message::find($messageId);
        
        if (!$message) return false;

        return $message->delivery_status === 'delivered';
    }

    protected function addTagToConversation(Conversation $conversation, ?string $tag): void
    {
        if (!$tag) return;

        $conversation->tags()->firstOrCreate([
            'tag' => $tag,
        ]);
    }

    protected function removeTagFromConversation(Conversation $conversation, ?string $tag): void
    {
        if (!$tag) return;

        $conversation->tags()->where('tag', $tag)->delete();
    }

    protected function scheduleNextStepAfterDelay(SequenceEnrollment $enrollment, SequenceStep $delayStep): void
    {
        $nextStep = $delayStep->getNextStep();
        
        if (!$nextStep) {
            // No next step, complete the enrollment
            $enrollment->complete();
            return;
        }
        
        // Get the delay from the delay step
        $delaySeconds = $delayStep->getDelayInSeconds();
        
        // Get sequence configuration
        $sequence = $enrollment->sequence;
        $timezone = $sequence->timezone ?? 'UTC';
        $businessHours = $sequence->business_hours ?? null;
        
        // Calculate next execution time respecting business hours
        $currentTime = now()->setTimezone($timezone);
        $scheduledAt = $this->businessHoursService->calculateNextExecutionTime(
            $currentTime, 
            $delaySeconds, 
            $businessHours, 
            $timezone
        );
        
        // Queue next step execution
        $execution = SequenceStepExecution::create([
            'sequence_id' => $enrollment->sequence_id,
            'sequence_enrollment_id' => $enrollment->id,
            'sequence_step_id' => $nextStep->id,
            'status' => 'pending',
            'scheduled_at' => $scheduledAt,
        ]);

        Log::info("scheduleNextStepAfterDelay: Created next step execution after delay", [
            'enrollment_id' => $enrollment->id,
            'delay_step_id' => $delayStep->id,
            'next_step_id' => $nextStep->id,
            'next_step_type' => $nextStep->step_type,
            'delay_seconds' => $delaySeconds,
            'scheduled_at' => $scheduledAt,
        ]);

        // Update enrollment next execution time
        $enrollment->scheduleNextExecution($delaySeconds);

        // Dispatch job with calculated delay
        $jobDelay = $scheduledAt->diffInSeconds(now());
        ExecuteSequenceStep::dispatch($execution->id)->delay($jobDelay);
        
        Log::info("scheduleNextStepAfterDelay: Dispatched next step job", [
            'execution_id' => $execution->id,
            'job_delay_seconds' => $jobDelay,
        ]);
    }

    protected function moveToNextStep(SequenceEnrollment $enrollment): void
    {
        $nextStep = $enrollment->moveToNextStep();

        if (!$nextStep) {
            // No next step, complete the enrollment
            $enrollment->complete();
            return;
        }
        
        // If the next step is a delay step, execute it immediately
        // The delay step will then schedule the following step with the delay
        if ($nextStep->isDelayStep()) {
            Log::info("moveToNextStep: Next step is delay, executing immediately", [
                'enrollment_id' => $enrollment->id,
                'next_step_id' => $nextStep->id,
            ]);
            
            // Create execution record for delay step (immediate)
            $execution = SequenceStepExecution::create([
                'sequence_id' => $enrollment->sequence_id,
                'sequence_enrollment_id' => $enrollment->id,
                'sequence_step_id' => $nextStep->id,
                'status' => 'pending',
                'scheduled_at' => now(),
            ]);
            
            // Dispatch immediately - delay step will handle scheduling the next step
            ExecuteSequenceStep::dispatch($execution->id);
            return;
        }
        
        // For non-delay steps, execute immediately
        $execution = SequenceStepExecution::create([
            'sequence_id' => $enrollment->sequence_id,
            'sequence_enrollment_id' => $enrollment->id,
            'sequence_step_id' => $nextStep->id,
            'status' => 'pending',
            'scheduled_at' => now(),
        ]);

        Log::info("moveToNextStep: Executing next step immediately", [
            'enrollment_id' => $enrollment->id,
            'next_step_id' => $nextStep->id,
            'next_step_type' => $nextStep->step_type,
        ]);

        ExecuteSequenceStep::dispatch($execution->id);
    }
}
