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
use App\Services\WebhookService;
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
    public const EVENT_TIME_TRIGGER = 'time_trigger';
    public const EVENT_ESCALATION_TRIGGERED = 'escalation_triggered';

    /**
     * Which workflow trigger_config['type'] values are compatible with each
     * automation event. Workflows whose trigger type is not listed for an
     * event are never evaluated for that event (e.g. a tag_added workflow is
     * not re-checked on every inbound message — tag_added events fire from
     * InboxController::addTag() instead).
     */
    private const EVENT_TRIGGER_TYPES = [
        self::EVENT_MESSAGE_RECEIVED => ['keyword', 'keyword_matched', 'message_received', 'first_contact'],
        self::EVENT_TAG_ADDED        => ['tag_added'],
        self::EVENT_SEQUENCE_COMPLETED => ['sequence_completed'],
        self::EVENT_ORDER_STATUS_CHANGED => ['order_status_changed'],
        self::EVENT_TIME_TRIGGER => ['time'],
        self::EVENT_ESCALATION_TRIGGERED => ['escalation_triggered'],
    ];

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
        if (!$testMode && app(AutomationRunControl::class)->isBusinessPaused((int) $businessId)) {
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
                    'exception_type' => get_class($e),
                ]);
            }
        }

        return $results;
    }

    public function replayFailedActions(AutomationWorkflow $workflow, WorkflowExecution $source): array
    {
        if (app(AutomationRunControl::class)->isBusinessPaused((int) $workflow->business_id)) {
            return ['success' => false, 'error' => 'automation_paused'];
        }
        if ($source->workflow_id !== $workflow->id || $source->test_mode || $source->status !== 'failed') {
            return ['success' => false, 'error' => 'execution_not_replayable'];
        }
        if (!$workflow->active) {
            return ['success' => false, 'error' => 'workflow_inactive'];
        }

        $conversation = $source->conversation;
        if (!$conversation || (int) $conversation->business_id !== (int) $workflow->business_id) {
            return ['success' => false, 'error' => 'conversation_unavailable'];
        }

        $failedRows = collect(data_get($source->results, 'actions_executed', []))
            ->filter(fn ($row) => is_array($row) && ($row['success'] ?? false) === false && isset($row['action_index']))
            ->values();
        if ($failedRows->isEmpty()) {
            return ['success' => false, 'error' => 'no_failed_actions_to_replay'];
        }

        $actions = $this->normalizedActions($workflow);
        $replayActions = [];
        foreach ($failedRows as $row) {
            $index = (int) $row['action_index'];
            $action = $actions[$index] ?? null;
            if (!$action || !hash_equals((string) ($row['action_hash'] ?? ''), hash('sha256', json_encode($action, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: ''))) {
                return ['success' => false, 'error' => 'workflow_action_changed_since_failure'];
            }
            $replayActions[$index] = $action;
        }

        try {
            $replay = WorkflowExecution::create([
                'workflow_id' => $workflow->id,
                'conversation_id' => $conversation->id,
                'business_id' => $conversation->business_id,
                'event_id' => 'replay:' . $source->id,
                'status' => 'running',
                'test_mode' => false,
                'trigger_data' => [
                    'event_type' => 'manual_replay',
                    'replay_of_execution_id' => $source->id,
                    'conversation_id' => $conversation->id,
                ],
                'started_at' => now(),
            ]);
        } catch (\Illuminate\Database\QueryException $exception) {
            if ($this->isDuplicateWorkflowEvent($exception)) {
                return ['success' => false, 'error' => 'failed_actions_already_replayed'];
            }
            throw $exception;
        }

        $results = ['triggered' => true, 'replay_of_execution_id' => $source->id, 'actions_executed' => [], 'errors' => []];
        foreach ($replayActions as $index => $action) {
            $actionResult = $this->executeAction($action, $conversation, false);
            $actionResult['action_index'] = $index;
            $actionResult['action_hash'] = hash('sha256', json_encode($action, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
            $actionResult['replayed'] = true;
            $results['actions_executed'][] = $actionResult;
            if (!$actionResult['success']) {
                $results['errors'][] = $actionResult['error'] ?? 'unknown_action_failure';
            }
        }

        $replay->update([
            'status' => empty($results['errors']) ? 'completed' : 'failed',
            'results' => $results,
            'completed_at' => now(),
        ]);

        return ['success' => empty($results['errors']), 'execution' => $replay->fresh(), 'results' => $results];
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
            if (!$testMode && app(AutomationRunControl::class)->isBusinessPaused((int) $workflow->business_id)) {
                return $results;
            }
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
            if (!$this->checkWorkflowConditions($workflow->conditions ?? [], $conversation, $eventContext)) {
                return $results;
            }

            $triggerType = self::triggerTypeOf($workflow);
            $configuredConditions = $workflow->conditions ?? [];
            $triggerConditions = $this->triggerConditionsOf($workflow->trigger_config ?? []);
            $hasSendMessageAction = collect($this->normalizedActions($workflow))->contains(fn (array $action) => ($action['type'] ?? null) === 'send_message');
            if ($eventType === self::EVENT_MESSAGE_RECEIVED
                && $triggerType === 'message_received'
                && $hasSendMessageAction
                && empty($triggerConditions)
                && empty($configuredConditions)) {
                Log::notice('AutomationEngine: blocked unconditional message_received send_message workflow', [
                    'workflow_id' => $workflow->id,
                    'conversation_id' => $conversation->id,
                ]);
                return $results;
            }

            $eventId = $eventType !== null ? $this->resolveEventId($eventType, $conversation, $eventContext) : null;

            if (!$testMode && WorkflowExecution::query()
                ->where('conversation_id', $conversation->id)
                ->where('test_mode', false)
                ->where('created_at', '>=', now()->subHour())
                ->count() >= 5) {
                Log::warning('AutomationEngine: hourly chained workflow run cap reached', [
                    'conversation_id' => $conversation->id,
                    'workflow_id' => $workflow->id,
                ]);
                return $results;
            }

            $results['triggered'] = true;

            try {
                $execution = WorkflowExecution::create([
                    'workflow_id' => $workflow->id,
                    'conversation_id' => $conversation->id,
                    'business_id' => $conversation->business_id,
                    'event_id' => $eventId,
                    'status' => 'running',
                    'test_mode' => $testMode,
                    'trigger_data' => [
                        'trigger_config' => $workflow->trigger_config,
                        'workflow_conditions' => $workflow->conditions ?? [],
                        'conversation_id' => $conversation->id,
                        'event_type' => $eventType ?? self::triggerTypeOf($workflow),
                        'event_id' => $eventId,
                        'chain' => array_values(array_slice((array) ($eventContext['workflow_chain'] ?? []), -5)),
                    ],
                    'started_at' => now(),
                ]);
            } catch (\Illuminate\Database\QueryException $exception) {
                if ($eventId !== null && $this->isDuplicateWorkflowEvent($exception)) {
                    $results['triggered'] = false;
                    Log::info('AutomationEngine: duplicate source event skipped', [
                        'workflow_id' => $workflow->id,
                        'conversation_id' => $conversation->id,
                        'event_type' => $eventType,
                        'event_id' => $eventId,
                    ]);
                    return $results;
                }
                throw $exception;
            }

            // Execute each action
            foreach ($this->normalizedActions($workflow) as $index => $action) {
                $actionResult = $this->blockedSequenceLoop($workflow, $action)
                    ? ['type' => 'start_sequence', 'success' => false, 'error' => 'workflow_loop_guard_24h']
                    : $this->executeAction($action, $conversation, $testMode);
                $actionResult['action_index'] = $index;
                $actionResult['action_hash'] = hash('sha256', json_encode($action, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
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
                'status' => empty($results['errors']) ? 'completed' : 'failed',
                'results' => $results,
                'completed_at' => now(),
            ]);

            Log::info('Automation workflow executed', [
                'workflow_id' => $workflow->id,
                'conversation_id' => $conversation->id,
                'test_mode' => $testMode,
                'action_count' => count($results['actions_executed'] ?? []),
                'failed_action_count' => count(array_filter(
                    $results['actions_executed'] ?? [],
                    fn ($result) => !is_array($result) || empty($result['success']),
                )),
            ]);

        } catch (\Throwable $e) {
            Log::error('Automation workflow execution failed', [
                'workflow_id' => $workflow->id,
                'conversation_id' => $conversation->id,
                'exception_type' => get_class($e),
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

    private function normalizedActions(AutomationWorkflow $workflow): array
    {
        return collect($workflow->actions_config ?? [])->map(function ($action) {
            if (!is_array($action)) {
                return [];
            }
            if (is_array($action['config'] ?? null)) {
                $action = array_merge($action['config'], $action);
                unset($action['config']);
            }
            return $action;
        })->values()->all();
    }

    private function blockedSequenceLoop(AutomationWorkflow $workflow, array $action): bool
    {
        if (($action['type'] ?? null) !== 'start_sequence') {
            return false;
        }

        $configuredSequenceId = data_get($workflow->trigger_config, 'conditions.sequence_id');
        return self::triggerTypeOf($workflow) === 'sequence_completed'
            && $configuredSequenceId !== null
            && (string) $configuredSequenceId === (string) ($action['sequence_id'] ?? '');
    }

    private function resolveEventId(string $eventType, Conversation $conversation, array $eventContext): string
    {
        $explicit = $eventContext['event_id'] ?? null;
        if (is_scalar($explicit) && (string) $explicit !== '') {
            return (string) $explicit;
        }

        return match ($eventType) {
            self::EVENT_MESSAGE_RECEIVED => isset($eventContext['message'])
                ? 'message:' . $eventContext['message']->id
                : 'message:' . $conversation->id . ':' . (string) ($eventContext['message_id'] ?? 'unknown'),
            self::EVENT_TAG_ADDED => 'tag:' . $conversation->id . ':' . (string) ($eventContext['tag_event_id'] ?? $eventContext['tag'] ?? 'unknown'),
            self::EVENT_SEQUENCE_COMPLETED => 'sequence-enrollment:' . (string) ($eventContext['sequence_enrollment_id'] ?? ($eventContext['sequence_id'] ?? 'unknown')),
            self::EVENT_ORDER_STATUS_CHANGED => 'order-status:' . (string) ($eventContext['order_id'] ?? 'unknown') . ':' . (string) ($eventContext['status'] ?? 'unknown'),
            self::EVENT_TIME_TRIGGER => 'time:' . now()->utc()->format('Y-m-d'),
            self::EVENT_ESCALATION_TRIGGERED => 'handoff:' . (string) ($eventContext['handoff_task_id'] ?? 'unknown') . ':' . (string) ($eventContext['opened_at'] ?? 'unknown'),
            default => $eventType . ':' . $conversation->id . ':' . (string) ($eventContext['source_id'] ?? 'unknown'),
        };
    }

    private function isDuplicateWorkflowEvent(\Illuminate\Database\QueryException $exception): bool
    {
        $message = strtolower($exception->getMessage());
        return str_contains($message, 'workflow_executions_workflow_conversation_event_unique')
            || (str_contains($message, 'workflow_executions.workflow_id')
                && str_contains($message, 'workflow_executions.conversation_id')
                && str_contains($message, 'workflow_executions.event_id'));
    }

    /**
     * Check if trigger conditions are met
     */
    private function checkTriggerConditions(array $triggerConfig, Conversation $conversation, array $eventContext = []): bool
    {
        $triggerType = (string) ($triggerConfig['type'] ?? '');
        $conditions = $this->triggerConditionsOf($triggerConfig);

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
                return $this->checkEscalationTrigger($conditions, $conversation, $eventContext);
            case 'sequence_completed':
                return $this->checkSequenceCompletedTrigger($conditions, $conversation);
            default:
                return false;
        }
    }

    private function triggerConditionsOf(array $triggerConfig): array
    {
        if (is_array($triggerConfig['conditions'] ?? null)) {
            return $triggerConfig['conditions'];
        }

        $conditions = $triggerConfig;
        unset($conditions['type']);
        return $conditions;
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
        $matchedKeywords = [];
        foreach ($keywords as $keyword) {
            if ($this->containsKeyword($messageText, (string) $keyword)) {
                $matchedKeywords[] = $keyword;
            }
        }

        if ($matchType === 'all') {
            return count($matchedKeywords) === count($keywords);
        } else {
            return count($matchedKeywords) > 0;
        }
    }

    private function checkWorkflowConditions(array $conditions, Conversation $conversation, array $eventContext): bool
    {
        if ($conditions === []) {
            return true;
        }

        $messageText = $eventContext['message'] instanceof Message
            ? (string) $eventContext['message']->content
            : (string) ($conversation->messages()->where('direction', 'inbound')->latest('id')->value('content') ?? '');
        $hasResult = false;
        $result = true;

        foreach ($conditions as $condition) {
            if (!is_array($condition)) {
                return false;
            }

            $config = is_array($condition['config'] ?? null) ? $condition['config'] : $condition;
            $type = strtolower((string) ($condition['type'] ?? $condition['field'] ?? $config['field'] ?? ''));
            $value = $config['value'] ?? $condition['value'] ?? null;
            $comparison = strtolower((string) ($condition['comparison'] ?? 'equals'));
            if (!is_scalar($value) || trim((string) $value) === '') {
                return false;
            }

            $matched = match ($type) {
                'customer_tag' => $comparison === 'contains'
                    ? $conversation->tags()->where('tag', 'like', '%' . addcslashes((string) $value, '%_') . '%')->exists()
                    : $conversation->tags()->where('tag', (string) $value)->exists(),
                'conversation_status' => strtolower((string) $conversation->status) === strtolower((string) $value),
                'message_contains' => $comparison === 'equals'
                    ? $this->normalizeKeywordText($messageText) === $this->normalizeKeywordText((string) $value)
                    : str_contains($this->normalizeKeywordText($messageText), $this->normalizeKeywordText((string) $value)),
                default => false,
            };

            if ($comparison === 'not_equals') {
                $matched = !$matched;
            } elseif (!in_array($comparison, ['equals', 'contains'], true)) {
                return false;
            }

            $operator = strtolower((string) ($condition['operator'] ?? 'and'));
            if (!$hasResult) {
                $result = $matched;
                $hasResult = true;
            } elseif ($operator === 'or') {
                $result = $result || $matched;
            } elseif ($operator === 'and') {
                $result = $result && $matched;
            } else {
                return false;
            }
        }

        return $hasResult && $result;
    }

    private function containsKeyword(string $text, string $keyword): bool
    {
        $text = $this->normalizeKeywordText($text);
        $keyword = $this->normalizeKeywordText($keyword);
        if ($keyword === '') {
            return false;
        }

        $pattern = '/(?<![\\p{L}\\p{N}_])' . preg_quote($keyword, '/') . '(?![\\p{L}\\p{N}_])/u';
        return preg_match($pattern, $text) === 1;
    }

    private function normalizeKeywordText(string $text): string
    {
        $text = mb_strtolower(trim($text), 'UTF-8');
        $text = preg_replace('/[\\x{0640}\\p{M}]/u', '', $text) ?? $text;
        return strtr($text, [
            'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا',
            'ى' => 'ي', 'ة' => 'ه',
        ]);
    }

    /**
     * Check time trigger conditions
     */
    private function checkTimeTrigger(array $conditions, Conversation $conversation): bool
    {
        try {
            $currentTime = now()->setTimezone($conversation->business?->timezone ?: 'UTC');
        } catch (\Throwable) {
            $currentTime = now()->setTimezone('UTC');
        }
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

            if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $startTime)
                || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $endTime)) {
                return false;
            }

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
    private function checkEscalationTrigger(array $conditions, Conversation $conversation, array $eventContext = []): bool
    {
        if (isset($eventContext['handoff_task'])) {
            $task = $eventContext['handoff_task'];
            if (!empty($conditions['priority']) && ($task->priority ?? null) !== $conditions['priority']) {
                return false;
            }
            if (!empty($conditions['reason']) && ($task->reason ?? null) !== $conditions['reason']) {
                return false;
            }
            return true;
        }

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
        $actionType = (string) ($action['type'] ?? '');
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
                'exception_type' => get_class($e),
            ]);
        }

        return $result;
    }

    /**
     * Execute send message action
     */
    private function executeSendMessage(array $action, Conversation $conversation, bool $testMode): array
    {
        $channel = $conversation->channel;
        if (!$channel) {
            return [
                'type' => 'send_message',
                'success' => false,
                'error' => 'No channel found for conversation'
            ];
        }

        $workflowReplySafetyEnabled = \App\Models\FeatureFlag::isEnabled(
            'phase5_workflow_reply_safety',
            (int) $conversation->business_id,
        );
        if (!$conversation->ai_enabled || ($workflowReplySafetyEnabled && $conversation->requires_human)) {
            return [
                'type' => 'send_message',
                'success' => false,
                'error' => $conversation->requires_human ? 'human_takeover' : 'ai_disabled',
            ];
        }

        $consent = app(ConsentGate::class)->checkConversation($conversation, 'marketing');
        if (!$consent->allowed) {
            return [
                'type' => 'send_message',
                'success' => false,
                'error' => 'Marketing send blocked: ' . $consent->reason,
            ];
        }

        if ($testMode) {
            return [
                'type' => 'send_message',
                'success' => true,
                'message' => 'Would send: ' . $action['message']
            ];
        }

        // Create message record first with pending status
        $message = Message::create([
            'conversation_id' => $conversation->id,
            'content' => $action['message'],
            'direction' => 'outbound',
            'status' => 'pending',
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
        $webhookService = app(WebhookService::class);
        $url = (string) ($action['url'] ?? '');
        $webhookService->validateDestination($url);

        if ($testMode) {
            return [
                'type' => 'webhook',
                'success' => true,
                'url' => $url,
            ];
        }

        $payload = [
            'event_type' => 'workflow.action',
            'timestamp' => now()->toIso8601String(),
            'business_id' => (int) $conversation->business_id,
            'conversation_id' => (int) $conversation->id,
        ];
        $signingSecret = (string) ($action['secret'] ?? config('app.key'));
        $response = $webhookService->postSignedJson($url, $payload, $signingSecret);

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
                'exception_type' => get_class($e),
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
