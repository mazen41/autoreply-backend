<?php

namespace App\Services;

use App\Models\AutomationWorkflow;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\ConversationTag;
use App\Models\Sequence;
use App\Services\SequenceTriggerService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AutomationEngine
{
    /**
     * Execute a workflow on a conversation
     */
    public function executeWorkflow(AutomationWorkflow $workflow, Conversation $conversation, bool $testMode = false): array
    {
        $results = [
            'triggered' => false,
            'actions_executed' => [],
            'errors' => []
        ];

        try {
            // Check if trigger conditions are met
            if (!$this->checkTriggerConditions($workflow->trigger_config, $conversation)) {
                return $results;
            }

            $results['triggered'] = true;

            // Execute each action
            foreach ($workflow->actions_config as $action) {
                $actionResult = $this->executeAction($action, $conversation, $testMode);
                $results['actions_executed'][] = $actionResult;

                if (!$actionResult['success']) {
                    $results['errors'][] = $actionResult['error'];
                }
            }

            // Update execution count if not in test mode
            if (!$testMode) {
                $workflow->increment('executions_count');
            }

            Log::info('Automation workflow executed', [
                'workflow_id' => $workflow->id,
                'conversation_id' => $conversation->id,
                'test_mode' => $testMode,
                'results' => $results
            ]);

        } catch (\Exception $e) {
            Log::error('Automation workflow execution failed', [
                'workflow_id' => $workflow->id,
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage()
            ]);
            $results['errors'][] = $e->getMessage();
        }

        return $results;
    }

    /**
     * Check if trigger conditions are met
     */
    private function checkTriggerConditions(array $triggerConfig, Conversation $conversation): bool
    {
        $triggerType = $triggerConfig['type'];
        $conditions = $triggerConfig['conditions'] ?? [];

        switch ($triggerType) {
            case 'keyword':
                return $this->checkKeywordTrigger($conditions, $conversation);
            case 'time':
                return $this->checkTimeTrigger($conditions, $conversation);
            case 'first_contact':
                return $this->checkFirstContactTrigger($conditions, $conversation);
            case 'tag_added':
                return $this->checkTagTrigger($conditions, $conversation);
            case 'message_received':
                return $this->checkMessageReceivedTrigger($conditions, $conversation);
            default:
                return false;
        }
    }

    /**
     * Check keyword trigger conditions
     */
    private function checkKeywordTrigger(array $conditions, Conversation $conversation): bool
    {
        $keywords = $conditions['keywords'] ?? [];
        $matchType = $conditions['match_type'] ?? 'any'; // 'any' or 'all'

        // Get recent messages
        $recentMessages = $conversation->messages()
            ->where('direction', 'inbound')
            ->orderBy('created_at', 'desc')
            ->take(5)
            ->get();

        $messageText = $recentMessages->pluck('content')->implode(' ');
        $messageTextLower = strtolower($messageText);

        $matchedKeywords = [];
        foreach ($keywords as $keyword) {
            if (str_contains($messageTextLower, strtolower($keyword))) {
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
     */
    private function checkFirstContactTrigger(array $conditions, Conversation $conversation): bool
    {
        $messageCount = $conversation->messages()->where('direction', 'inbound')->count();
        return $messageCount === 1;
    }

    /**
     * Check tag trigger
     */
    private function checkTagTrigger(array $conditions, Conversation $conversation): bool
    {
        $tags = $conditions['tags'] ?? [];
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
                    $result = $this->executeWebhook($action, $conversation, $testMode);
                    break;
                case 'pause_ai':
                    $result = $this->executePauseAI($action, $conversation, $testMode);
                    break;
                case 'start_sequence':
                    $result = $this->executeStartSequence($action, $conversation, $testMode);
                    break;
                default:
                    $result['error'] = "Unknown action type: {$actionType}";
            }
        } catch (\Exception $e) {
            $result['error'] = $e->getMessage();
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

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'content' => $action['message'],
            'direction' => 'outbound',
            'status' => 'sent',
            'is_ai' => false,
            'source' => 'automation',
            'send_status' => 'pending',
        ]);

        // Send through channel
        $this->sendMessageThroughChannel($conversation->channel, $conversation, $message);

        return [
            'type' => 'send_message',
            'success' => true,
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
            'source' => 'automation'
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

        // Use SequenceTriggerService to enroll
        $sequenceTriggerService = new SequenceTriggerService();
        
        try {
            $enrollment = $sequenceTriggerService->enrollInSequence($sequence, $conversation, false);
            
            return [
                'type' => 'start_sequence',
                'success' => true,
                'enrollment_id' => $enrollment?->id ?? null
            ];
        } catch (\Exception $e) {
            return [
                'type' => 'start_sequence',
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Send message through appropriate channel
     */
    private function sendMessageThroughChannel($channel, $conversation, $message): void
    {
        // This would use the existing send logic from ProcessAutoReply
        // For now, just mark as sent
        $message->update(['send_status' => 'sent']);
        
        Log::info('Automation message sent', [
            'channel_type' => $channel->type,
            'conversation_id' => $conversation->id,
            'message_id' => $message->id
        ]);
    }
}