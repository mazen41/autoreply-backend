<?php

namespace App\Services;

use App\Models\AutomationWorkflow;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\ConversationTag;
use App\Models\Sequence;
use App\Models\WorkflowExecution;
use App\Services\SequenceTriggerService;
use App\Services\SequenceEnrollmentService;
use App\Services\ChannelMessageService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AutomationEngine
{
    /**
     * Automation event types. Each event carries its own context
     * ($eventContext) and can only fire a subset of workflow trigger types —
     * see EVENT_TRIGGER_TYPES.
     */
    public const EVENT_MESSAGE_RECEIVED = 'message_received';
    public const EVENT_TAG_ADDED = 'tag_added';
    public const EVENT_SEQUENCE_COMPLETED = 'sequence_completed';
    public const EVENT_ORDER_STATUS_CHANGED = 'order_status_changed';

    /**
     * Which workflow trigger_config['type'] values are compatible with each
     * automation event. Workflows whose trigger type is not listed for an
     * event are never evaluated for that event (e.g. a tag_added workflow is
     * not re-checked on every inbound message — tag_added events fire from
     * InboxController::addTag() instead).
     */
    private const EVENT_TRIGGER_TYPES = [
        self::EVENT_MESSAGE_RECEIVED => ['keyword', 'keyword_matched', 'message_received', 'first_contact', 'time'],
        self::EVENT_TAG_ADDED        => ['tag_added'],
        self::EVENT_SEQUENCE_COMPLETED => ['sequence_completed'],
        self::EVENT_ORDER_STATUS_CHANGED => ['order_status_changed'],
    ];

    /**
     * Idempotency window in seconds. Prevents duplicate workflow executions
     * for the same workflow + conversation + event within this time window.
     */
    private const IDEMPOTENCY_WINDOW_SECONDS = 300; // 5 minutes

    /**
     * Execute every active workflow for a business that is compatible with
     * the given automation event.
     *
     * This is the single entry point used by event sources
     * (ProcessAutoReply for message_received, InboxController::addTag for
     * tag_added). Event compatibility is filtered here, BEFORE
     * executeWorkflow, so incompatible workflows are never touched.
     *
     * Bot scope: only business-global workflows (bot_id NULL — all
     * pre-existing rows) plus workflows bound to the conversation's resolved
     * bot are eligible. A bot-specific workflow never fires on another
     * bot's conversation, and never fires when no bot is resolved.
     */
    public function executeWorkflowsForEvent(string $eventType, Conversation $conversation, array $eventContext = [], bool $testMode = false): array
    {
        $businessId = $conversation->business_id;
        if (!$businessId) {
            return [];
        }

        $botId = $conversation->bot_id ? (int) $conversation->bot_id : null;
        $compatibleTypes = self::EVENT_TRIGGER_TYPES[$eventType] ?? [];

        $workflows = AutomationWorkflow::forBusiness($businessId)
            ->active()
            ->forBotScope($botId)
            ->get()
            ->filter(function (AutomationWorkflow $workflow) use ($compatibleTypes) {
                $triggerType = self::triggerTypeOf($workflow);

                return $triggerType !== '' && in_array($triggerType, $compatibleTypes, true);
            });

        if ($workflows->isEmpty()) {
            return [];
        }

        $results = [];

        foreach ($workflows as $workflow) {
            try {
                $results[] = $this->executeWorkflow(
                    $workflow,
                    $conversation,
                    testMode: $testMode,
                    eventType: $eventType,
                    eventContext: $eventContext
                );
            } catch (\Throwable $e) {
                Log::error('AutomationEngine: workflow execution failed', [
                    'workflow_id'     => $workflow->id,
                    'conversation_id' => $conversation->id,
                    'event_type'      => $eventType,
                    'error'           => $e->getMessage(),
                ]);
            }
        }

        return $results;
    }

    public static function triggerTypeOf(AutomationWorkflow $workflow): string
    {
        return (string) ($workflow->trigger_config['type'] ?? '');
    }

    public static function triggerMatchesEvent(string $triggerType, string $eventType): bool
    {
        return in_array($triggerType, self::EVENT_TRIGGER_TYPES[$eventType] ?? [], true);
    }

    /**
     * Execute a workflow on a conversation
     *
     * $eventType restricts evaluation to triggers compatible with the firing
     * event (null = unrestricted, used by the explicit test endpoint).
     * $eventContext carries event data: ['message' => Message|null,
     * 'tag' => string|null].
     */
    public function executeWorkflow(AutomationWorkflow $workflow, Conversation $conversation, bool $testMode = false, ?string $eventType = null, array $eventContext = []): array
    {
        $results = [
            'triggered' => false,
            'actions_executed' => [],
            'errors' => []
        ];

        try {
            // Event compatibility gate — never run a workflow whose trigger
            // type cannot fire on this event.
            if ($eventType !== null) {
                $triggerType = self::triggerTypeOf($workflow);

                if ($triggerType === '' || !self::triggerMatchesEvent($triggerType, $eventType)) {
                    return $results;
                }
            }

            // Check if trigger conditions are met BEFORE creating any record —
            // a WorkflowExecution row only exists for workflows that actually
            // matched.
            if (!$this->checkTriggerConditions($workflow->trigger_config, $conversation, $eventContext)) {
                return $results;
            }

            // Deduplication: prevent duplicate execution for same workflow +
            // conversation + event within idempotency window.
            if (!$testMode && $eventType !== null) {
                $recentExecution = WorkflowExecution::where('workflow_id', $workflow->id)
                    ->where('conversation_id', $conversation->id)
                    ->where('status', 'completed')
                    ->where('created_at', '>=', now()->subSeconds(self::IDEMPOTENCY_WINDOW_SECONDS))
                    ->whereJsonContains('trigger_data->event_type', $eventType)
                    ->first();

                if ($recentExecution) {
                    Log::info('AutomationEngine: duplicate execution skipped (idempotency window)', [
                        'workflow_id' => $workflow->id,
                        'conversation_id' => $conversation->id,
                        'event_type' => $eventType,
                        'existing_execution_id' => $recentExecution->id,
                    ]);
                    return $results;
                }
            }

            $results['triggered'] = true;

            $execution = WorkflowExecution::create([
                'workflow_id' => $workflow->id,
                'conversation_id' => $conversation->id,
                'business_id' => $conversation->business_id,
                'status' => 'running',
                'test_mode' => $testMode,
                'trigger_data' => [
                    'trigger_config' => $workflow->trigger_config,
                    'conversation_id' => $conversation->id,
                    'event_type' => $eventType ?? self::triggerTypeOf($workflow),
                ],
                'started_at' => now(),
            ]);

            // Execute each action
            foreach ($workflow->actions_config as $action) {
                $actionResult = $this->executeAction($action, $conversation, $testMode);
                $results['actions_executed'][] = $actionResult;

                if (!$actionResult['success']) {
                    $results['errors'][] = $actionResult['error'] ?? 'Unknown error';
                }
            }

            // Update execution count if not in test mode
            if (!$testMode) {
                $workflow->increment('executions_count');
                $workflow->update(['last_executed_at' => now()]);
            }

            $execution->update([
                'status' => 'completed',
                'results' => $results,
                'completed_at' => now(),
            ]);

            Log::info('Automation workflow executed', [
                'workflow_id' => $workflow->id,
                'conversation_id' => $conversation->id,
                'test_mode' => $testMode,
                'results' => $results
            ]);

        } catch (\Throwable $e) {
            Log::error('Automation workflow execution failed', [
                'workflow_id' => $workflow->id,
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage()
            ]);

            if (isset($execution)) {
                $execution->update([
                    'status' => 'failed',
                    'error_message' => $e->getMessage(),
                    'results' => $results,
                    'completed_at' => now(),
                ]);
            }

            $results['errors'][] = $e->getMessage();
        }

        return $results;
    }

    /**
     * Check if trigger conditions are met
     */
    private function checkTriggerConditions(array $triggerConfig, Conversation $conversation, array $eventContext = []): bool
    {
        $triggerType = $triggerConfig['type'];
        $conditions = $triggerConfig['conditions'] ?? [];

        switch ($triggerType) {
            case 'keyword':
            case 'keyword_matched':
                return $this->checkKeywordTrigger($conditions, $conversation, $eventContext['message'] ?? null);
            case 'time':
                return $this->checkTimeTrigger($conditions, $conversation);
            case 'first_contact':
                return $this->checkFirstContactTrigger($conditions, $conversation, $eventContext['message'] ?? null);
            case 'tag_added':
                return $this->checkTagTrigger($conditions, $conversation, $eventContext['tag'] ?? null);
            case 'message_received':
                return $this->checkMessageReceivedTrigger($conditions, $conversation);
            case 'order_status_changed':
                return $this->checkOrderStatusTrigger($conditions, $conversation, $eventContext);
            case 'escalation_triggered':
                return $this->checkEscalationTrigger($conditions, $conversation);
            case 'sequence_completed':
                return $this->checkSequenceCompletedTrigger($conditions, $conversation);
            default:
                return false;
        }
    }

    /**
     * Check keyword trigger conditions against the message that fired the
     * event. Only evaluates the current inbound message — never historical
     * messages. Returns false when no message is in context (e.g. test endpoint).
     */
    private function checkKeywordTrigger(array $conditions, Conversation $conversation, ?Message $message = null): bool
    {
        $keywords = $conditions['keywords'] ?? [];
        $matchType = $conditions['match_type'] ?? 'any'; // 'any' or 'all'

        if ($message !== null) {
            $messageText = (string) $message->content;
        } else {
            // Legacy/test path (no event context): fall back to the
            // conversation's most recent inbound message.
            $messageText = (string) ($conversation->messages()
                ->where('direction', 'inbound')
                ->orderBy('id', 'desc')
                ->value('content') ?? '');
        }
        $messageTextLower = mb_strtolower($messageText);

        $matchedKeywords = [];
        foreach ($keywords as $keyword) {
            if (str_contains($messageTextLower, mb_strtolower((string) $keyword))) {
                $matchedKeywords[] = $keyword;
            }
        }

        if ($matchType === 'all') {
            return count($matchedKeywords) === count($keywords);
        } else {
            return count($matchedKeywords) > 0;
        }
    }

    /**
     * Check time trigger conditions
     */
    private function checkTimeTrigger(array $conditions, Conversation $conversation): bool
    {
        $currentTime = now();
        $currentDay = strtolower($currentTime->format('l'));
        $currentTimeHours = $currentTime->format('H:i');

        // Check day conditions
        if (!empty($conditions['days'])) {
            if (!in_array($currentDay, array_map('strtolower', $conditions['days']))) {
                return false;
            }
        }

        // Check time conditions
        if (!empty($conditions['time_range'])) {
            $startTime = $conditions['time_range']['start'] ?? '00:00';
            $endTime = $conditions['time_range']['end'] ?? '23:59';

            if ($currentTimeHours < $startTime || $currentTimeHours > $endTime) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check first contact trigger
     *
     * Matches only when the message that fired the event IS the first
     * inbound message on the conversation (id <= comparison handles
     * concurrent workers). Evaluation happens after the AI reply is sent, so
     * a plain inbound count would be wrong for conversations where a second
     * customer message arrived meanwhile — the current message's identity is
     * what matters, not the post-reply message count.
     */
    private function checkFirstContactTrigger(array $conditions, Conversation $conversation, ?Message $message = null): bool
    {
        if ($message !== null) {
            $inboundCount = Message::where('conversation_id', $conversation->id)
                ->where('direction', 'inbound')
                ->where('id', '<=', $message->id)
                ->count();
        } else {
            // No message in context (test endpoint): fall back to "the
            // conversation has exactly one inbound message".
            $inboundCount = $conversation->messages()->where('direction', 'inbound')->count();
        }

        return $inboundCount === 1;
    }

    /**
     * Check tag trigger
     *
     * For tag_added events the tag that was just added is matched against
     * the configured tags. Without event context (test endpoint), falls back
     * to "conversation currently has any of the configured tags".
     */
    private function checkTagTrigger(array $conditions, Conversation $conversation, ?string $eventTag = null): bool
    {
        $tags = $conditions['tags'] ?? [];

        // Single-tag configs: accept both {tags: [...]} and {tag: 'x'}.
        if (!empty($conditions['tag'])) {
            $tags = array_merge($tags, [$conditions['tag']]);
        }

        if ($eventTag !== null) {
            return in_array($eventTag, $tags, true);
        }

        $conversationTags = $conversation->tags()->pluck('tag')->toArray();

        foreach ($tags as $tag) {
            if (in_array($tag, $conversationTags)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check message received trigger
     */
    private function checkMessageReceivedTrigger(array $conditions, Conversation $conversation): bool
    {
        // Check if message was received recently
        if (!empty($conditions['time_condition'])) {
            if ($conditions['time_condition'] === 'outside_hours') {
                // Check if current time is outside business hours
                $businessHoursCheck = AICapabilitiesService::checkBusinessHours(
                    $conversation->channel->business
                );
                return $businessHoursCheck['is_after_hours'];
            }
        }

        return true; // Default: any message received
    }

    /**
     * Check order status changed trigger
     *
     * The event payload's status takes precedence (the order_status_changed
     * event carries the new status); without payload context we fall back
     * to the conversation's persisted checkout_state.
     */
    private function checkOrderStatusTrigger(array $conditions, Conversation $conversation, array $eventContext = []): bool
    {
        $orderStatus = $conditions['order_status'] ?? null;
        if (!$orderStatus) {
            return false;
        }

        // Prefer the event payload — the status that fired the event.
        $eventStatus = $eventContext['status'] ?? null;
        if ($eventStatus !== null) {
            return $eventStatus === $orderStatus;
        }

        // Check conversation checkout_state for order status
        $checkoutState = $conversation->checkout_state ?? [];
        $currentStatus = $checkoutState['status'] ?? null;

        if ($currentStatus && $currentStatus === $orderStatus) {
            return true;
        }

        // Check conditions for specific status transitions
        if (!empty($conditions['statuses']) && is_array($conditions['statuses'])) {
            return in_array($currentStatus, $conditions['statuses']);
        }

        return false;
    }

    /**
     * Check escalation triggered
     */
    private function checkEscalationTrigger(array $conditions, Conversation $conversation): bool
    {
        // Check if conversation is currently escalated
        if ($conversation->requires_human) {
            return true;
        }

        // Check for escalation keywords in recent messages
        if (!empty($conditions['keywords']) && is_array($conditions['keywords'])) {
            $recentMessages = $conversation->messages()
                ->where('direction', 'inbound')
                ->orderBy('created_at', 'desc')
                ->take(3)
                ->get();

            $messageText = mb_strtolower($recentMessages->pluck('content')->implode(' '));
            foreach ($conditions['keywords'] as $keyword) {
                if (str_contains($messageText, mb_strtolower($keyword))) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Check sequence completed trigger
     */
    private function checkSequenceCompletedTrigger(array $conditions, Conversation $conversation): bool
    {
        $sequenceId = $conditions['sequence_id'] ?? null;

        $query = \App\Models\SequenceEnrollment::where('conversation_id', $conversation->id)
            ->where('status', 'completed');

        if ($sequenceId) {
            $query->where('sequence_id', $sequenceId);
        }

        return $query->exists();
    }

    /**
     * Execute a single action
     */
    private function executeAction(array $action, Conversation $conversation, bool $testMode): array
    {
        $actionType = $action['type'];
        $result = [
            'type' => $actionType,
            'success' => false,
            'error' => null
        ];

        try {
            switch ($actionType) {
                case 'send_message':
                    $result = $this->executeSendMessage($action, $conversation, $testMode);
                    break;
                case 'add_tag':
                    $result = $this->executeAddTag($action, $conversation, $testMode);
                    break;
                case 'remove_tag':
                    $result = $this->executeRemoveTag($action, $conversation, $testMode);
                    break;
                case 'escalate':
                    $result = $this->executeEscalate($action, $conversation, $testMode);
                    break;
                case 'webhook':
                case 'send_webhook':
                    $result = $this->executeWebhook($action, $conversation, $testMode);
                    break;
                case 'pause_ai':
                    $result = $this->executePauseAI($action, $conversation, $testMode);
                    break;
                case 'toggle_ai':
                    $result = $this->executeToggleAI($action, $conversation, $testMode);
                    break;
                case 'assign_agent':
                    $result = $this->executeAssignAgent($action, $conversation, $testMode);
                    break;
                case 'update_custom_field':
                    $result = $this->executeUpdateCustomField($action, $conversation, $testMode);
                    break;
                case 'start_sequence':
                    $result = $this->executeStartSequence($action, $conversation, $testMode);
                    break;
                case 'stop_sequence':
                    $result = $this->executeStopSequence($action, $conversation, $testMode);
                    break;
                default:
                    $result['error'] = "Unknown action type: {$actionType}";
            }
        } catch (\Throwable $e) {
            $result['error'] = $e->getMessage();
            Log::error('AutomationEngine: action execution error', [
                'action_type' => $actionType,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }

        return $result;
    }

    /**
     * Execute send message action
     */
    private function executeSendMessage(array $action, Conversation $conversation, bool $testMode): array
    {
        if ($testMode) {
            return [
                'type' => 'send_message',
                'success' => true,
                'message' => 'Would send: ' . $action['message']
            ];
        }

        $channel = $conversation->channel;
        if (!$channel) {
            return [
                'type' => 'send_message',
                'success' => false,
                'error' => 'No channel found for conversation'
            ];
        }

        // Create message record first with pending status
        $message = Message::create([
            'conversation_id' => $conversation->id,
            'content' => $action['message'],
            'direction' => 'outbound',
            'status' => 'sent',
            'is_ai' => false,
            'source' => 'automation',
            'send_status' => 'pending',
        ]);

        // Send through channel provider
        // ChannelMessageService handles provider call and status update
        $channelMessageService = app(ChannelMessageService::class);
        $sendSuccess = $channelMessageService->sendMessage($channel, $conversation, $message);

        return [
            'type' => 'send_message',
            'success' => $sendSuccess,
            'message_id' => $message->id
        ];
    }

    /**
     * Execute add tag action
     */
    private function executeAddTag(array $action, Conversation $conversation, bool $testMode): array
    {
        if ($testMode) {
            return [
                'type' => 'add_tag',
                'success' => true,
                'tag' => $action['tag']
            ];
        }

        $tag = ConversationTag::firstOrCreate([
            'conversation_id' => $conversation->id,
            'tag' => $action['tag'],
        ], [
            'source' => 'manual',
        ]);

        return [
            'type' => 'add_tag',
            'success' => true,
            'tag_id' => $tag->id
        ];
    }

    /**
     * Execute remove tag action
     */
    private function executeRemoveTag(array $action, Conversation $conversation, bool $testMode): array
    {
        if ($testMode) {
            return [
                'type' => 'remove_tag',
                'success' => true,
                'tag' => $action['tag']
            ];
        }

        ConversationTag::where('conversation_id', $conversation->id)
            ->where('tag', $action['tag'])
            ->delete();

        return [
            'type' => 'remove_tag',
            'success' => true
        ];
    }

    /**
     * Execute escalate action
     */
    private function executeEscalate(array $action, Conversation $conversation, bool $testMode): array
    {
        if ($testMode) {
            return [
                'type' => 'escalate',
                'success' => true,
                'reason' => $action['reason'] ?? 'automation'
            ];
        }

        $conversation->update([
            'requires_human' => true,
            'escalated_at' => now(),
            'escalation_reason' => $action['reason'] ?? 'automation',
            'escalation_notified' => false
        ]);

        return [
            'type' => 'escalate',
            'success' => true
        ];
    }

    /**
     * Execute webhook action
     */
    private function executeWebhook(array $action, Conversation $conversation, bool $testMode): array
    {
        if ($testMode) {
            return [
                'type' => 'webhook',
                'success' => true,
                'url' => $action['url']
            ];
        }

        $response = Http::timeout(10)->post($action['url'], [
            'conversation_id' => $conversation->id,
            'conversation_data' => $conversation->toArray(),
            'triggered_at' => now()->toIso8601String()
        ]);

        return [
            'type' => 'webhook',
            'success' => $response->successful(),
            'status_code' => $response->status()
        ];
    }

    /**
     * Execute pause AI action
     */
    private function executePauseAI(array $action, Conversation $conversation, bool $testMode): array
    {
        if ($testMode) {
            return [
                'type' => 'pause_ai',
                'success' => true,
                'duration' => $action['duration'] ?? null
            ];
        }

        $conversation->update(['ai_enabled' => false]);

        return [
            'type' => 'pause_ai',
            'success' => true
        ];
    }

    /**
     * Execute start sequence action
     */
    private function executeStartSequence(array $action, Conversation $conversation, bool $testMode): array
    {
        if ($testMode) {
            return [
                'type' => 'start_sequence',
                'success' => true,
                'sequence_id' => $action['sequence_id'] ?? null
            ];
        }

        $sequenceId = $action['sequence_id'] ?? null;
        if (!$sequenceId) {
            return [
                'type' => 'start_sequence',
                'success' => false,
                'error' => 'Sequence ID is required'
            ];
        }

        $sequence = Sequence::find($sequenceId);
        if (!$sequence) {
            return [
                'type' => 'start_sequence',
                'success' => false,
                'error' => 'Sequence not found'
            ];
        }

        // Verify business ownership
        if ($sequence->business_id !== $conversation->business_id) {
            return [
                'type' => 'start_sequence',
                'success' => false,
                'error' => 'Sequence does not belong to this business'
            ];
        }

        // Use SequenceTriggerService to enroll via container
        $sequenceTriggerService = app(SequenceTriggerService::class);

        try {
            // Debug: log canEnroll result
            $enrollmentService = app(SequenceEnrollmentService::class);
            $canEnroll = $enrollmentService->canEnroll($sequence, $conversation);
            Log::info('AutomationEngine: start_sequence canEnroll check', [
                'sequence_id' => $sequence->id,
                'sequence_status' => $sequence->status,
                'steps_count' => $sequence->steps()->count(),
                'conversation_id' => $conversation->id,
                'can_enroll' => $canEnroll,
                'business_match' => $sequence->business_id === $conversation->business_id,
            ]);

            $enrollment = $sequenceTriggerService->enrollInSequence($sequence, $conversation, false);

            Log::info('AutomationEngine: enrollment result', [
                'enrollment' => $enrollment?->id,
            ]);

            return [
                'type' => 'start_sequence',
                'success' => $enrollment !== null,
                'enrollment_id' => $enrollment?->id ?? null,
                'error' => $enrollment === null ? 'canEnroll returned false' : null,
            ];
        } catch (\Exception $e) {
            Log::error('AutomationEngine: start_sequence exception', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return [
                'type' => 'start_sequence',
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Execute stop sequence action
     */
    private function executeStopSequence(array $action, Conversation $conversation, bool $testMode): array
    {
        if ($testMode) {
            return [
                'type' => 'stop_sequence',
                'success' => true,
                'sequence_id' => $action['sequence_id'] ?? null
            ];
        }

        $sequenceId = $action['sequence_id'] ?? null;
        if (!$sequenceId) {
            return [
                'type' => 'stop_sequence',
                'success' => false,
                'error' => 'Sequence ID is required'
            ];
        }

        $sequence = Sequence::find($sequenceId);
        if (!$sequence) {
            return [
                'type' => 'stop_sequence',
                'success' => false,
                'error' => 'Sequence not found'
            ];
        }

        // Verify business ownership
        if ($sequence->business_id !== $conversation->business_id) {
            return [
                'type' => 'stop_sequence',
                'success' => false,
                'error' => 'Sequence does not belong to this business'
            ];
        }

        // Stop active enrollments for this conversation and sequence
        $enrollmentService = app(SequenceEnrollmentService::class);

        try {
            // Stop all enrollments for this specific sequence and conversation
            $stopped = \App\Models\SequenceEnrollment::where('sequence_id', $sequence->id)
                ->where('conversation_id', $conversation->id)
                ->where('status', 'active')
                ->update(['status' => 'stopped', 'stopped_at' => now(), 'stop_reason' => 'workflow_action']);

            return [
                'type' => 'stop_sequence',
                'success' => true,
                'stopped_count' => $stopped
            ];
        } catch (\Exception $e) {
            return [
                'type' => 'stop_sequence',
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Execute toggle AI action
     */
    private function executeToggleAI(array $action, Conversation $conversation, bool $testMode): array
    {
        if ($testMode) {
            return [
                'type' => 'toggle_ai',
                'success' => true,
                'ai_enabled' => $action['ai_enabled'] ?? true,
            ];
        }

        $aiEnabled = $action['ai_enabled'] ?? true;
        $conversation->update(['ai_enabled' => $aiEnabled]);

        return [
            'type' => 'toggle_ai',
            'success' => true,
            'ai_enabled' => $aiEnabled,
        ];
    }

    /**
     * Execute assign agent action
     */
    private function executeAssignAgent(array $action, Conversation $conversation, bool $testMode): array
    {
        if ($testMode) {
            return [
                'type' => 'assign_agent',
                'success' => true,
                'agent_id' => $action['agent_id'] ?? null,
            ];
        }

        $agentId = $action['agent_id'] ?? null;
        if (!$agentId) {
            return [
                'type' => 'assign_agent',
                'success' => false,
                'error' => 'Agent ID is required'
            ];
        }

        // Verify agent belongs to the same business
        $agent = \App\Models\TeamMember::where('user_id', $agentId)
            ->where('business_id', $conversation->business_id)
            ->where('is_active', true)
            ->first();

        if (!$agent) {
            return [
                'type' => 'assign_agent',
                'success' => false,
                'error' => 'Agent not found or not in this business'
            ];
        }

        $conversation->update([
            'assigned_agent_id' => $agentId,
            'assigned_at' => now(),
        ]);

        // Broadcast agent assignment event
        if ($conversation->channel && $conversation->channel->user_id) {
            broadcast(new \App\Events\MessageReceived(
                $conversation->messages()->latest('id')->first() ?? new Message(),
                $conversation,
                $conversation->channel->user_id
            ));
        }

        // Audit log
        \App\Services\AuditLoggerService::log('conversation.agent_assigned', $conversation, [
            'agent_id' => $agentId,
        ]);

        return [
            'type' => 'assign_agent',
            'success' => true,
            'agent_id' => $agentId,
        ];
    }

    /**
     * Execute update custom field action
     */
    private function executeUpdateCustomField(array $action, Conversation $conversation, bool $testMode): array
    {
        if ($testMode) {
            return [
                'type' => 'update_custom_field',
                'success' => true,
                'field' => $action['field'] ?? null,
                'value' => $action['value'] ?? null,
            ];
        }

        $field = $action['field'] ?? null;
        $value = $action['value'] ?? null;

        if (!$field) {
            return [
                'type' => 'update_custom_field',
                'success' => false,
                'error' => 'Field name is required'
            ];
        }

        // Update or create the customer record via the shared resolver.
        // (The previous Customer::firstOrCreate() here matched on
        // business_profile_id ALONE, collapsing every customer of the business
        // into one shared record — CustomerService matches per-sender identity.)
        $customer = app(\App\Services\CustomerService::class)->attachToConversation($conversation);

        if (!$customer) {
            return [
                'type' => 'update_custom_field',
                'success' => false,
                'error' => 'No business profile associated with this conversation'
            ];
        }

        $customFields = $customer->custom_fields ?? [];
        $customFields[$field] = $value;
        $customer->update(['custom_fields' => $customFields]);

        return [
            'type' => 'update_custom_field',
            'success' => true,
            'field' => $field,
            'value' => $value,
            'customer_id' => $customer->id,
        ];
    }

}