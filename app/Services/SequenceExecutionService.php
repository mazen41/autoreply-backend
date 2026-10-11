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

        // Do not mark the execution complete before the provider action has
        // finished. A worker crash after the send must remain recoverable.
        $execution->status = 'processing';
        $execution->save();

        try {
            switch ($step->step_type) {
                case 'message':
                    if ($this->executeMessageStep($execution, $enrollment, $step)) {
                        $this->moveToNextStep($enrollment);
                    }
                    break;
                case 'delay':
                    $this->executeDelayStep($execution, $enrollment, $step);
                    // Delay step should schedule the next step with the delay
                    $this->scheduleNextStepAfterDelay($enrollment, $step);
                    break;
                case 'condition':
                    $this->executeConditionStep($execution, $enrollment, $step);
                    // Condition step handles moving to next step, jumping, or stopping internally
                    break;
                case 'action':
                    $this->executeActionStep($execution, $enrollment, $step);
                    // Only move to next step if action didn't stop sequence
                    if (($execution->metadata['action_type'] ?? '') !== 'stop_sequence') {
                        $this->moveToNextStep($enrollment);
                    }
                    break;
                default:
                    $execution->markAsSkipped('unknown_step_type');
            }
        } catch (\Exception $e) {
            if ($e instanceof \App\Exceptions\AutomationPausedException) {
                throw $e;
            }
            // Record the failure on the execution record even when the
            // caller doesn't route through the job's catch (retry) layer.
            $execution->markAsFailed($e->getMessage());
            throw $e;
        }

        if (!in_array($execution->status, ['failed', 'skipped'], true)) {
            $execution->markAsExecuted($execution->message_id);
        } else {
            $execution->save();
        }
    }

    protected function executeMessageStep(SequenceStepExecution $execution, SequenceEnrollment $enrollment, SequenceStep $step): bool
    {
        $conversation = $enrollment->conversation;
        $sequence = $enrollment->sequence;

        if (!$conversation) {
            throw new \Exception('Conversation not found');
        }

        // A sequence belongs to the conversation's channel/account. Never
        // silently switch to another connected account of the same type.
        $channel = $conversation->channel;
        $expectedChannelType = strtolower((string) $sequence->channel);
        // The sequence editor calls its email channel "email" while connected
        // Gmail accounts are persisted as "gmail" channels.
        if ($expectedChannelType === 'email') {
            $expectedChannelType = 'gmail';
        }
        if (!$channel || (int) $channel->business_id !== (int) $sequence->business_id
            || ($expectedChannelType !== '' && strtolower((string) $channel->type) !== $expectedChannelType)) {
            $this->pauseForUnavailableConversationChannel($execution, $enrollment, $conversation);
            return false;
        }
        if ($channel->status !== 'connected') {
            $this->pauseForUnavailableConversationChannel($execution, $enrollment, $conversation);
            return false;
        }

        $consent = app(ConsentGate::class)->checkConversation($conversation, 'marketing');
        if (!$consent->allowed) {
            $reason = 'consent_' . $consent->reason;
            $execution->markAsSkipped($reason);
            $enrollment->stop($reason);
            Log::info('Sequence message blocked by consent gate', [
                'sequence_id' => $sequence->id,
                'enrollment_id' => $enrollment->id,
                'execution_id' => $execution->id,
                'conversation_id' => $conversation->id,
                'reason' => $consent->reason,
            ]);
            return false;
        }

        // CRITICAL FIX: Move external provider call OUTSIDE DB transaction
        // to prevent rollback after provider success causing duplicate sends.
        // New order:
        // 1. Create message record (in transaction)
        // 2. Call provider (outside transaction)
        // 3. Update status based on provider result (outside transaction)

        $messageRecord = DB::transaction(function () use ($conversation, $step, $execution, $channel) {
            $existing = $execution->message_id
                ? Message::query()->lockForUpdate()->find($execution->message_id)
                : null;

            if ($existing) {
                return $existing;
            }

            $message = Message::create([
                'conversation_id' => $conversation->id,
                'content' => $step->message,
                'type' => 'text',
                'direction' => 'outbound',
                'status' => 'pending',
                'is_ai' => true,
                'source' => 'sequence',
                'delivery_status' => 'pending',
                'metadata' => array_merge($step->config ?? [], [
                    'sequence_automated' => true,
                    'channel_type' => $channel->type,
                ]),
            ]);

            app(AiReplyAuditService::class)->record($message, [
                'commerce_connection_id' => $conversation->ecommerce_connection_id,
                'prompt_version' => 'sequence-content',
                'ai_provider' => 'automation',
                'ai_model' => 'sequence-step',
                'escalation_reason' => 'automated_sequence_step',
                'consent_basis' => 'marketing_opt_in',
            ]);

            // Link the durable outbound row before contacting the provider. A
            // retry can then inspect its provider ID and reuse the same row.
            $execution->message_id = $message->id;
            $execution->save();

            return $message;
        });

        if (app(\App\Services\UsageMeter::class)->blockMessageIfOverLimit($sequence->business, $messageRecord)) {
            $reason = $messageRecord->fresh()->error_details ?: 'business_plan_limit_reached';
            $execution->markAsSkipped('plan_limit_exceeded');
            $enrollment->stop('plan_limit_exceeded');
            Log::notice('SequenceExecutionService: sequence message blocked by business plan limit', [
                'business_id' => $sequence->business_id,
                'sequence_id' => $sequence->id,
                'enrollment_id' => $enrollment->id,
                'execution_id' => $execution->id,
                'message_id' => $messageRecord->id,
                'reason' => $reason,
            ]);
            return false;
        }

        if ($this->hasProviderAcceptanceEvidence($messageRecord)) {
            $messageRecord->update([
                'status' => 'sent',
                'send_status' => 'sent',
                'delivery_status' => 'sent',
                'error_details' => null,
            ]);
            return true;
        }

        // Recheck the business and sequence pause immediately before the
        // provider call. Preserve this step as pending so queue release can
        // continue it after the merchant resumes automation.
        if ($sequence->status === 'paused'
            || app(\App\Services\AutomationRunControl::class)->isBusinessPaused((int) $sequence->business_id)) {
            $execution->status = 'pending';
            $execution->save();
            $messageRecord->update(['status' => 'queued', 'send_status' => 'pending']);
            throw new \App\Exceptions\AutomationPausedException('Automation paused before sequence delivery.');
        }

        // Now call the provider OUTSIDE the transaction
        try {
            $this->sendMessageToConversation($conversation, $step->message, $channel, $execution->message_id, $step->config ?? []);
        } catch (\Exception $e) {
            // Provider failed - update message status to failed
            Message::where('id', $execution->message_id)->update([
                'status' => 'failed',
                'send_status' => 'failed',
                'delivery_status' => 'failed',
                'error_details' => $e->getMessage(),
            ]);
            throw $e; // Re-throw to trigger retry logic
        }

        return true;
    }

    private function pauseForUnavailableConversationChannel(
        SequenceStepExecution $execution,
        SequenceEnrollment $enrollment,
        Conversation $conversation,
    ): void {
        $reason = 'channel_disconnected';
        $execution->markAsSkipped($reason);
        $enrollment->pause($reason);

        try {
            app(\App\Services\HandoffTaskService::class)->openForConversation($conversation, $reason, 'high');
        } catch (\Throwable $exception) {
            Log::error('SequenceExecutionService: failed to open channel-disconnected handoff', [
                'conversation_id' => $conversation->id,
                'enrollment_id' => $enrollment->id,
                'exception_type' => get_class($exception),
            ]);
        }

        Log::warning('SequenceExecutionService: paused sequence because conversation channel is unavailable', [
            'conversation_id' => $conversation->id,
            'enrollment_id' => $enrollment->id,
            'channel_id' => $conversation->channel_id,
            'reason' => $reason,
        ]);
    }

    private function hasProviderAcceptanceEvidence(Message $message): bool
    {
        $metadata = is_array($message->metadata) ? $message->metadata : [];
        $providerIds = $metadata['provider_message_ids'] ?? [];

        return !empty($metadata['platform_message_id'])
            || (is_array($providerIds) && $providerIds !== [])
            || !empty($message->whatsapp_message_id)
            || !empty($message->gmail_message_id)
            || in_array($message->send_status, ['sent', 'delivered', 'read'], true)
            || in_array($message->status, ['sent', 'delivered', 'read'], true);
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
        $conditionConfig = $step->condition_config ?? [];
        $conditionResult = app(ConditionEvaluationService::class)->evaluate($enrollment, $step, $conditionConfig);

        $execution->metadata = array_merge($execution->metadata ?? [], [
            'condition_result' => $conditionResult,
            'condition_config' => $conditionConfig,
        ]);

        if ($conditionResult) {
            $onTrue = $conditionConfig['on_true'] ?? 'continue';
            if ($onTrue === 'jump' && isset($conditionConfig['true_step_order'])) {
                $this->jumpToStep($enrollment, (int)$conditionConfig['true_step_order']);
            } elseif ($onTrue === 'stop') {
                $enrollment->stop('condition_true_stop');
            } else { // 'continue'
                $this->moveToNextStep($enrollment);
            }
        } else {
            $onFalse = $conditionConfig['on_false'] ?? 'stop';
            if ($onFalse === 'jump' && isset($conditionConfig['false_step_order'])) {
                $this->jumpToStep($enrollment, (int)$conditionConfig['false_step_order']);
            } elseif ($onFalse === 'continue') {
                $this->moveToNextStep($enrollment);
            } else { // 'stop'
                $enrollment->stop('condition_failed');
                $execution->markAsSkipped('condition_failed');
            }
        }
    }

    public function jumpToStep(SequenceEnrollment $enrollment, int $targetStepOrder): void
    {
        $targetStep = $enrollment->sequence->steps()
            ->where('step_order', $targetStepOrder)
            ->active()
            ->first();

        if (!$targetStep) {
            $enrollment->complete();
            $this->fireSequenceCompletedEvent($enrollment);
            return;
        }

        $fromStepOrder = (int) $enrollment->current_step;
        $enrollment->current_step = $targetStep->step_order;
        $enrollment->save();

        $scheduledAt = now();
        $execution = SequenceStepExecution::createForEnrollment(
            $enrollment,
            $targetStep,
            $scheduledAt,
            ['jumped_from_step_order' => $fromStepOrder],
        );

        if (!$execution) {
            Log::warning('Sequence jump stopped by the per-enrollment execution cap', [
                'enrollment_id' => $enrollment->id,
                'target_step_id' => $targetStep->id,
            ]);
            return;
        }

        ExecuteSequenceStep::dispatch($execution->id);
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

    protected function sendMessageToConversation(Conversation $conversation, string $message, Channel $channel, int $messageId, array $config = []): void
    {
        $messageRecord = Message::find($messageId);
        if (!$messageRecord) {
            throw new \Exception("Message record not found: {$messageId}");
        }

        // Send based on channel type
        try {
            switch ($channel->type) {
                case 'whatsapp':
                    $this->sendWhatsAppMessage($conversation, $message, $channel, $messageRecord);
                    break;
                case 'facebook':
                case 'instagram':
                    // Phase 4: Facebook/Instagram sequence support — routes
                    // through the SAME MetaMessengerService implementation the
                    // AI auto-reply path uses (Meta Graph Send API), so sequence
                    // messages behave identically to AI replies on these
                    // channels. Failures throw → the ExecuteSequenceStep job
                    // retries (3 attempts, 30/60/120s backoff) and records the
                    // error on both the execution and the Message row.
                    $this->sendMetaMessage($conversation, $message, $channel, $messageRecord);
                    break;
                case 'telegram':
                    $this->sendTelegramMessage($conversation, $message, $channel, $messageRecord);
                    break;
                case 'email':
                case 'gmail':
                    $this->sendEmailMessage($conversation, $message, $channel, $messageRecord);
                    break;
                default:
                    // Hard, honest limitation — e.g. TikTok has no public
                    // outbound comment-reply/DM API (see sendTikTokReply in
                    // ProcessAutoReply). Never fake a send.
                    Log::warning("Unsupported channel type for sequence: {$channel->type}");
                    throw new \Exception("Channel type {$channel->type} is not supported for sequence messages");
            }

            // Provider succeeded - update delivery status
            $messageRecord->update([
                'status' => 'sent',
                'send_status' => 'sent',
                'delivery_status' => 'sent',
            ]);

        } catch (\Exception $e) {
            Log::error("Failed to send sequence message", [
                'message_id' => $messageRecord->id,
                'channel_type' => $channel->type,
                'exception_type' => get_class($e),
            ]);
            $messageRecord->update([
                'status' => 'failed',
                'send_status' => 'failed',
                'delivery_status' => 'failed',
                'error_details' => $e->getMessage(),
            ]);
            throw $e; // Re-throw to trigger retry logic
        }
    }

    protected function sendMetaMessage(Conversation $conversation, string $message, Channel $channel, Message $messageRecord): void
    {
        // Facebook and Instagram both use the Meta Graph Send API with the
        // page/account access token — identical wire behavior to AI replies.
        $result = app(\App\Services\MetaMessengerService::class)->sendTextWithResult(
            $channel,
            (string) $conversation->sender_id,
            $message
        );

        $metadata = $messageRecord->metadata ?? [];
        $metadata['provider_message_ids'] = $result['message_ids'] ?? [];
        if (!empty($result['message_id'])) {
            $metadata['platform_message_id'] = $result['message_id'];
        }
        $messageRecord->update([
            'metadata' => $metadata,
            'error_details' => $result['error'] ?? null,
        ]);

        if (!$result['success']) {
            throw new \Exception($result['error'] ?: "Meta {$channel->type} send rejected by provider.");
        }

        $messageRecord->update([
            'status' => 'sent',
            'send_status' => 'sent',
            'delivery_status' => 'sent',
        ]);
    }

    protected function sendWhatsAppMessage(Conversation $conversation, string $message, Channel $channel, Message $messageRecord): void
    {
        $messageRecord->content = $message;
        if (!app(ChannelMessageService::class)->sendMessage($channel, $conversation, $messageRecord)) {
            throw new \RuntimeException($messageRecord->fresh()->error_details ?: 'WhatsApp message could not be sent through the configured provider.');
        }
    }

    protected function sendTelegramMessage(Conversation $conversation, string $message, Channel $channel, Message $messageRecord): void
    {
        // Use existing TelegramService
        $telegramService = new TelegramService();

        // Channel::access_token decrypts the persisted token. Metadata may be
        // stale or user-controlled and is not an authorization source.
        $botToken = $channel->access_token;

        if (!$botToken) {
            Log::error("Telegram bot token not found in channel metadata", [
                'channel_id' => $channel->id,
            ]);
            throw new \Exception('Telegram bot token not configured');
        }

        $providerMessageId = null;
        $success = $telegramService->sendMessage($botToken, $conversation->sender_id, $message, $providerMessageId);

        if (!$success) {
            throw new \Exception('Failed to send Telegram message');
        }

        $updates = [
            'status' => 'sent',
            'send_status' => 'sent',
            'delivery_status' => 'sent',
        ];
        if ($providerMessageId) {
            $metadata = $messageRecord->metadata ?? [];
            $metadata['provider_message_ids'] = [$providerMessageId];
            $metadata['platform_message_id'] = $providerMessageId;
            $updates['metadata'] = $metadata;
        }
        $messageRecord->update($updates);
    }

    protected function sendEmailMessage(Conversation $conversation, string $message, Channel $channel, Message $messageRecord): void
    {
        if ($channel->type === 'gmail') {
            if (!app(ChannelMessageService::class)->sendMessage($channel, $conversation, $messageRecord)) {
                throw new \RuntimeException($messageRecord->fresh()->error_details ?: 'Gmail message could not be sent through the connected account.');
            }
            return;
        }

        throw new \RuntimeException('Sequence email messages require a connected Gmail channel.');
    }

    protected function evaluateCondition(SequenceEnrollment $enrollment, array $conditionConfig): bool
    {
        return app(ConditionEvaluationService::class)->evaluate($enrollment, $enrollment->getCurrentStep() ?? new SequenceStep(), $conditionConfig);
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
            Log::error('Failed to parse time condition', ['exception_type' => get_class($e)]);
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
            $this->fireSequenceCompletedEvent($enrollment);
            return;
        }
        
        // Update enrollment to next step BEFORE scheduling
        $enrollment->current_step = $nextStep->step_order;
        $enrollment->save();
        
        // Get the delay from the delay step
        $delaySeconds = $delayStep->getDelayInSeconds();
        
        // Get sequence configuration
        $sequence = $enrollment->sequence;
        $timezone = $sequence->timezone ?? 'UTC';
        $businessHours = $sequence->business_hours ?? null;
        
        Log::info("scheduleNextStepAfterDelay: Delay calculation input", [
            'enrollment_id' => $enrollment->id,
            'delay_step_id' => $delayStep->id,
            'delay_hours' => $delayStep->delay_hours,
            'delay_unit' => $delayStep->delay_unit,
            'calculated_delay_seconds' => $delaySeconds,
            'sequence_timezone' => $timezone,
            'business_hours' => $businessHours,
        ]);
        
        // Calculate next execution time respecting business hours
        $currentTime = now()->setTimezone($timezone);
        $scheduledAt = $this->businessHoursService->calculateNextExecutionTime(
            $currentTime, 
            $delaySeconds, 
            $businessHours, 
            $timezone
        );
        
        Log::info("scheduleNextStepAfterDelay: Time calculation", [
            'current_time' => $currentTime,
            'scheduled_at' => $scheduledAt,
            'diff_seconds' => $scheduledAt->diffInSeconds($currentTime),
        ]);
        
        // Keep $scheduledAt as a Carbon instance for the delay math below —
        // convert to string only for the execution key / DB columns.
        $execution = SequenceStepExecution::createForEnrollment($enrollment, $nextStep, $scheduledAt);
        if (!$execution) {
            Log::warning('Sequence delay continuation stopped by the per-enrollment execution cap', [
                'enrollment_id' => $enrollment->id,
                'step_id' => $nextStep->id,
            ]);
            return;
        }

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

        // Dispatch job with correct positive delay.
        //
        // ROOT CAUSE OF BUG 2 (fixed here):
        // The old code used $scheduledAt->diffInSeconds(now()) which in some
        // Carbon versions returns a float via direct timestamp subtraction
        // (scheduled_unix - now_unix) rather than calling Carbon's internal
        // abs-based diff. When $scheduledAt is in the future,
        // scheduled_unix > now_unix, so the result should be positive — but
        // the log showed -119.99, meaning the subtraction was inverted.
        //
        // Fix: use Carbon's signed diff explicitly:
        //   now()->diffInSeconds($scheduledAt, false)
        // false = don't take absolute value, so future dates return positive.
        $jobDelay = (int) round(now()->diffInSeconds($scheduledAt, false));

        Log::info('scheduleNextStepAfterDelay: Job dispatch calculation', [
            'execution_id'      => $execution->id,
            'scheduled_at'      => $scheduledAt->toISOString(),
            'now'               => now()->toISOString(),
            'job_delay_seconds' => $jobDelay,
        ]);

        if ($jobDelay <= 0) {
            Log::warning('scheduleNextStepAfterDelay: job_delay_seconds is zero or negative — scheduled_at is in the past or now; dispatching with minimum 1s delay', [
                'execution_id'  => $execution->id,
                'job_delay'     => $jobDelay,
                'scheduled_at'  => $scheduledAt->toISOString(),
                'delay_seconds' => $delaySeconds,
            ]);
            // Do NOT dispatch immediately (that would bypass the delay entirely).
            // Use a 1-second minimum so the job is still queued asynchronously.
            ExecuteSequenceStep::dispatch($execution->id)->delay(1);
        } else {
            ExecuteSequenceStep::dispatch($execution->id)->delay($jobDelay);
        }
        
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
            $this->fireSequenceCompletedEvent($enrollment);
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
            $scheduledAt = now();
            $execution = SequenceStepExecution::createForEnrollment($enrollment, $nextStep, $scheduledAt);
            if (!$execution) {
                Log::warning('Sequence continuation stopped by the per-enrollment execution cap', [
                    'enrollment_id' => $enrollment->id,
                    'step_id' => $nextStep->id,
                ]);
                return;
            }

            // Dispatch immediately - delay step will handle scheduling the next step
            ExecuteSequenceStep::dispatch($execution->id);
            return;
        }

        // For non-delay steps, execute immediately
        $scheduledAt = now();
        $execution = SequenceStepExecution::createForEnrollment($enrollment, $nextStep, $scheduledAt);
        if (!$execution) {
            Log::warning('Sequence continuation stopped by the per-enrollment execution cap', [
                'enrollment_id' => $enrollment->id,
                'step_id' => $nextStep->id,
            ]);
            return;
        }

        Log::info("moveToNextStep: Executing next step immediately", [
            'enrollment_id' => $enrollment->id,
            'next_step_id' => $nextStep->id,
            'next_step_type' => $nextStep->step_type,
        ]);

        ExecuteSequenceStep::dispatch($execution->id);
    }

    /**
     * Fire sequence_completed event to AutomationEngine.
     * Called when a sequence enrollment completes all steps.
     */
    private function fireSequenceCompletedEvent(SequenceEnrollment $enrollment): void
    {
        try {
            $conversation = $enrollment->conversation;
            if (!$conversation) {
                return;
            }

            $engine = app(\App\Services\AutomationEngine::class);
            $engine->executeWorkflowsForEvent(
                \App\Services\AutomationEngine::EVENT_SEQUENCE_COMPLETED,
                $conversation,
                ['sequence_id' => $enrollment->sequence_id, 'sequence_enrollment_id' => $enrollment->id]
            );
        } catch (\Throwable $e) {
            Log::error('SequenceExecutionService: failed to fire sequence_completed event', [
                'enrollment_id' => $enrollment->id,
                'exception_type' => get_class($e),
            ]);
        }
    }
}
