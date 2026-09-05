<?php

namespace App\Services;

use App\Models\SequenceStepExecution;
use App\Models\SequenceEnrollment;
use App\Models\SequenceStep;
use App\Models\Message;
use App\Models\Conversation;
use App\Models\Channel;
use App\Services\BusinessHoursService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class ConditionEvaluationService
{
    public function __construct(
        protected BusinessHoursService $businessHoursService
    ) {}

    /**
     * Main entry point to evaluate condition_config for a given enrollment and step.
     */
    public function evaluate(SequenceEnrollment $enrollment, SequenceStep $step, array $conditionConfig): bool
    {
        if (empty($conditionConfig)) {
            return true;
        }

        // Support group conditions with "match": "all" (AND) or "any" (OR)
        if (isset($conditionConfig['match']) && is_array($conditionConfig['conditions'] ?? null)) {
            $matchMode = strtolower($conditionConfig['match']) === 'any' ? 'any' : 'all';
            $conditions = $conditionConfig['conditions'];

            if (empty($conditions)) {
                return true;
            }

            if ($matchMode === 'any') {
                foreach ($conditions as $subCond) {
                    if ($this->evaluateSingle($enrollment, $step, $subCond)) {
                        return true;
                    }
                }
                return false;
            } else { // 'all' (AND)
                foreach ($conditions as $subCond) {
                    if (!$this->evaluateSingle($enrollment, $step, $subCond)) {
                        return false;
                    }
                }
                return true;
            }
        }

        // Single condition object
        return $this->evaluateSingle($enrollment, $step, $conditionConfig);
    }

    /**
     * Evaluate a single condition node.
     */
    public function evaluateSingle(SequenceEnrollment $enrollment, SequenceStep $step, array $config): bool
    {
        // Support nested condition group inside single node if passed recursively
        if (isset($config['match']) && is_array($config['conditions'] ?? null)) {
            return $this->evaluate($enrollment, $step, $config);
        }

        $type = $config['type'] ?? $config['field'] ?? null;
        $operator = $config['operator'] ?? 'equals';
        $targetValue = $config['value'] ?? $config['target'] ?? null;

        if (!$type) {
            return true;
        }

        $conversation = $enrollment->conversation;
        if (!$conversation) {
            return false;
        }

        return match ($type) {
            // ── 1. CUSTOMER CONDITIONS ───────────────────────────────────────
            'customer_tag', 'has_tag', 'tag' => $this->evaluateCustomerTag($conversation, $operator, $targetValue ?? ($config['tag'] ?? null)),
            'customer_does_not_have_tag' => $this->evaluateCustomerTag($conversation, 'does_not_have_tag', $targetValue ?? ($config['tag'] ?? null)),
            'customer_field' => $this->evaluateCustomerField($conversation, $config['field_name'] ?? $config['field'] ?? 'name', $operator, $targetValue),
            'customer_field_equals' => $this->evaluateCustomerField($conversation, $config['field_name'] ?? 'name', 'equals', $targetValue),
            'customer_field_does_not_equal' => $this->evaluateCustomerField($conversation, $config['field_name'] ?? 'name', 'not_equals', $targetValue),
            'customer_field_contains' => $this->evaluateCustomerField($conversation, $config['field_name'] ?? 'name', 'contains', $targetValue),
            'customer_exists' => !empty($conversation->sender_id),

            // ── 2. CONVERSATION CONDITIONS ──────────────────────────────────
            'customer_replied' => $this->evaluateCustomerReplied($enrollment, $step),
            'conversation_status', 'status_equals' => $this->evaluateOperator($operator, $conversation->status, $targetValue),
            'last_message_from_customer' => $this->evaluateLastMessageDirection($conversation, 'inbound'),
            'last_message_from_ai' => $this->evaluateLastMessageFromAi($conversation),
            'is_escalated', 'has_been_escalated' => $conversation->requires_human === true || !empty($conversation->escalated_at),
            'is_not_escalated', 'has_not_been_escalated' => $conversation->requires_human === false && empty($conversation->escalated_at),

            // ── 3. MESSAGE CONDITIONS ───────────────────────────────────────
            'message_text', 'message_contains' => $this->evaluateMessageText($conversation, $operator ?: 'contains', $targetValue),
            'message_does_not_contain' => $this->evaluateMessageText($conversation, 'does_not_contain', $targetValue),
            'message_equals' => $this->evaluateMessageText($conversation, 'equals', $targetValue),
            'message_does_not_equal' => $this->evaluateMessageText($conversation, 'not_equals', $targetValue),
            'message_language', 'language_equals' => $this->evaluateMessageProperty($conversation, 'detected_language', $operator, $targetValue),
            'message_intent', 'intent_equals' => $this->evaluateMessageProperty($conversation, 'intent', $operator, $targetValue),

            // ── 4. AI CONDITIONS ─────────────────────────────────────────────
            'ai_confidence', 'ai_confidence_greater_than' => $this->evaluateAiConfidence($conversation, $operator ?: 'greater_than', $targetValue),
            'ai_confidence_less_than' => $this->evaluateAiConfidence($conversation, 'less_than', $targetValue),
            'ai_confidence_equals' => $this->evaluateAiConfidence($conversation, 'equals', $targetValue),
            'ai_intent', 'ai_intent_equals' => $this->evaluateMessageProperty($conversation, 'intent', $operator, $targetValue, true),
            'ai_escalation', 'needs_escalation', 'ai_needs_escalation' => $conversation->requires_human === true,
            'does_not_need_escalation', 'ai_does_not_need_escalation' => $conversation->requires_human === false,

            // ── 5. ORDER CONDITIONS ──────────────────────────────────────────
            'has_order' => !empty($conversation->checkout_state['order_id'] ?? $conversation->checkout_state['id'] ?? null),
            'does_not_have_order' => empty($conversation->checkout_state['order_id'] ?? $conversation->checkout_state['id'] ?? null),
            'order_status', 'order_status_equals' => $this->evaluateOperator($operator ?: 'equals', $conversation->checkout_state['status'] ?? null, $targetValue),
            'order_status_does_not_equal' => $this->evaluateOperator('not_equals', $conversation->checkout_state['status'] ?? null, $targetValue),
            'order_total', 'order_total_greater_than' => $this->evaluateOperator($operator ?: 'greater_than', (float)($conversation->checkout_state['total'] ?? 0), (float)$targetValue),
            'order_total_less_than' => $this->evaluateOperator('less_than', (float)($conversation->checkout_state['total'] ?? 0), (float)$targetValue),
            'product_exists' => $this->evaluateProductExists($conversation, $targetValue),

            // ── 6. CHANNEL CONDITIONS ────────────────────────────────────────
            'channel' => $this->evaluateChannel($conversation, $operator, $targetValue),
            'channel_equals_whatsapp'  => strtolower($conversation->channel?->type ?? '') === 'whatsapp',
            'channel_equals_instagram' => strtolower($conversation->channel?->type ?? '') === 'instagram',
            'channel_equals_facebook'  => strtolower($conversation->channel?->type ?? '') === 'messenger',
            'channel_equals_telegram'  => strtolower($conversation->channel?->type ?? '') === 'telegram',
            'channel_equals_gmail'     => strtolower($conversation->channel?->type ?? '') === 'email',

            // ── 7. TIME CONDITIONS ───────────────────────────────────────────
            'within_business_hours' => $this->evaluateBusinessHours($enrollment, true),
            'outside_business_hours' => $this->evaluateBusinessHours($enrollment, false),
            'day_of_week' => $this->evaluateDayOfWeek($enrollment, $operator, $targetValue),
            'time_of_day' => $this->evaluateTimeOfDay($enrollment, $operator, $targetValue),

            default => true,
        };
    }

    /**
     * Customer Replied condition logic.
     * Checks if customer sent an inbound message AFTER the message/event that started this cycle.
     */
    protected function evaluateCustomerReplied(SequenceEnrollment $enrollment, SequenceStep $step): bool
    {
        $conversation = $enrollment->conversation;
        if (!$conversation) return false;

        // 1. Find timestamp of the previous message step executed in this enrollment
        $lastMessageExecution = SequenceStepExecution::where('sequence_enrollment_id', $enrollment->id)
            ->where('status', 'executed')
            ->whereNotNull('executed_at')
            ->whereHas('step', fn($q) => $q->where('step_type', 'message'))
            ->latest('executed_at')
            ->first();

        $cycleStartTime = null;

        if ($lastMessageExecution && $lastMessageExecution->executed_at) {
            $cycleStartTime = $lastMessageExecution->executed_at;
        } elseif (!empty($enrollment->trigger_ai_reply_id)) {
            $aiMsg = Message::find($enrollment->trigger_ai_reply_id);
            if ($aiMsg) {
                $cycleStartTime = $aiMsg->created_at;
            }
        }

        if (!$cycleStartTime) {
            $cycleStartTime = $enrollment->started_at ?? now()->subDays(1);
        }

        // Check if there is an INBOUND message created at or after cycleStartTime
        return $conversation->messages()
            ->where('direction', 'inbound')
            ->where('created_at', '>=', $cycleStartTime)
            ->exists();
    }

    protected function evaluateCustomerTag(Conversation $conversation, string $operator, ?string $tag): bool
    {
        if (!$tag) return false;
        $exists = $conversation->tags()->where('tag', $tag)->exists();
        return match ($operator) {
            'does_not_have_tag', 'not_equals', 'does_not_contain' => !$exists,
            default => $exists,
        };
    }

    protected function evaluateCustomerField(Conversation $conversation, string $field, string $operator, $targetValue): bool
    {
        $actualValue = match ($field) {
            'name', 'sender_name' => $conversation->sender_name,
            'email', 'sender_email' => $conversation->sender_email,
            'phone', 'sender_id' => $conversation->sender_id,
            default => $conversation->checkout_state[$field] ?? $conversation->metadata[$field] ?? null,
        };

        return $this->evaluateOperator($operator, $actualValue, $targetValue);
    }

    protected function evaluateLastMessageDirection(Conversation $conversation, string $direction): bool
    {
        $latest = $conversation->messages()->latest('id')->first();
        if (!$latest) return false;
        return $latest->direction === $direction;
    }

    protected function evaluateLastMessageFromAi(Conversation $conversation): bool
    {
        $latest = $conversation->messages()->latest('id')->first();
        if (!$latest) return false;
        return $latest->direction === 'outbound' && $latest->is_ai;
    }

    protected function evaluateMessageText(Conversation $conversation, string $operator, $targetValue): bool
    {
        $latestInbound = $conversation->messages()->where('direction', 'inbound')->latest('id')->first();
        if (!$latestInbound) return false;
        return $this->evaluateOperator($operator, $latestInbound->content, $targetValue);
    }

    protected function evaluateMessageProperty(Conversation $conversation, string $property, string $operator, $targetValue, bool $onlyAi = false): bool
    {
        $query = $conversation->messages()->latest('id');
        if ($onlyAi) {
            $query->where('direction', 'outbound')->where('is_ai', true);
        }
        $msg = $query->first();
        if (!$msg) return false;
        return $this->evaluateOperator($operator, $msg->{$property} ?? null, $targetValue);
    }

    protected function evaluateAiConfidence(Conversation $conversation, string $operator, $targetValue): bool
    {
        $aiMsg = $conversation->messages()->where('direction', 'outbound')->where('is_ai', true)->latest('id')->first();
        if (!$aiMsg || $aiMsg->confidence_score === null) return false;

        $score = (float)$aiMsg->confidence_score;
        $target = (float)$targetValue;

        // If target > 1 (e.g. 80 meaning 80%), normalize score to 0-100 if score is 0-1
        if ($target > 1.0 && $score <= 1.0) {
            $score = $score * 100;
        } elseif ($target <= 1.0 && $score > 1.0) {
            $target = $target * 100;
        }

        return $this->evaluateOperator($operator, $score, $target);
    }

    protected function evaluateProductExists(Conversation $conversation, $targetValue): bool
    {
        if (!$targetValue) return false;
        $checkout = $conversation->checkout_state ?? [];
        $items = $checkout['items'] ?? [];
        if (is_array($items)) {
            foreach ($items as $item) {
                $name = is_array($item) ? ($item['name'] ?? '') : (string)$item;
                if (str_contains(mb_strtolower($name), mb_strtolower($targetValue))) {
                    return true;
                }
            }
        }
        $productName = $checkout['product_name'] ?? '';
        return str_contains(mb_strtolower($productName), mb_strtolower($targetValue));
    }

    protected function evaluateChannel(Conversation $conversation, string $operator, $targetValue): bool
    {
        $channelType = strtolower($conversation->channel?->type ?? '');
        return $this->evaluateOperator($operator, $channelType, strtolower((string)$targetValue));
    }

    protected function evaluateBusinessHours(SequenceEnrollment $enrollment, bool $within): bool
    {
        $sequence = $enrollment->sequence;
        $timezone = $sequence?->timezone ?? $enrollment->conversation?->business?->timezone ?? 'UTC';
        $now = now()->setTimezone($timezone);

        $isWithin = $this->businessHoursService->isWithinBusinessHours($now, $sequence?->business_hours, $timezone);
        return $within ? $isWithin : !$isWithin;
    }

    protected function evaluateDayOfWeek(SequenceEnrollment $enrollment, string $operator, $targetValue): bool
    {
        $sequence = $enrollment->sequence;
        $timezone = $sequence?->timezone ?? 'UTC';
        $dayName = now()->setTimezone($timezone)->format('l'); // Monday, Tuesday...
        return $this->evaluateOperator($operator, $dayName, $targetValue);
    }

    protected function evaluateTimeOfDay(SequenceEnrollment $enrollment, string $operator, $targetValue): bool
    {
        $sequence = $enrollment->sequence;
        $timezone = $sequence?->timezone ?? 'UTC';
        $currentTime = now()->setTimezone($timezone)->format('H:i'); // 14:30
        return $this->evaluateOperator($operator, $currentTime, $targetValue);
    }

    /**
     * General operator evaluation logic.
     */
    public function evaluateOperator(string $operator, $actual, $target): bool
    {
        $op = strtolower($operator);
        $actStr = is_null($actual) ? '' : (string)$actual;
        $tgtStr = is_null($target) ? '' : (string)$target;

        switch ($op) {
            case 'equals':
            case 'equal':
            case '==':
            case '=':
                return strtolower(trim($actStr)) === strtolower(trim($tgtStr));

            case 'not_equals':
            case 'not_equal':
            case '!=':
                return strtolower(trim($actStr)) !== strtolower(trim($tgtStr));

            case 'contains':
                return !empty($tgtStr) && str_contains(mb_strtolower($actStr), mb_strtolower($tgtStr));

            case 'does_not_contain':
            case 'not_contains':
                return !str_contains(mb_strtolower($actStr), mb_strtolower($tgtStr));

            case 'starts_with':
                return !empty($tgtStr) && str_starts_with(mb_strtolower($actStr), mb_strtolower($tgtStr));

            case 'ends_with':
                return !empty($tgtStr) && str_ends_with(mb_strtolower($actStr), mb_strtolower($tgtStr));

            case 'greater_than':
            case '>':
                return (float)$actual > (float)$target;

            case 'greater_than_or_equal':
            case 'gte':
            case '>=':
                return (float)$actual >= (float)$target;

            case 'less_than':
            case '<':
                return (float)$actual < (float)$target;

            case 'less_than_or_equal':
            case 'lte':
            case '<=':
                return (float)$actual <= (float)$target;

            case 'exists':
            case 'is_true':
                return !empty($actual) && $actual !== false;

            case 'does_not_exist':
            case 'is_false':
                return empty($actual) || $actual === false;

            case 'has_tag':
                return !empty($actual);

            case 'does_not_have_tag':
                return empty($actual);

            default:
                return strtolower(trim($actStr)) === strtolower(trim($tgtStr));
        }
    }
}
