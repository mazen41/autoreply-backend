<?php

namespace App\Services;

use App\Models\BusinessProfile;
use App\Models\Conversation;
use App\Models\ConversationTag;
use App\Models\Message;
use App\Models\MessageFeedback;
use App\Models\User;
use Carbon\Carbon;

/**
 * Computes ALL training-dashboard statistics from real database records
 * (messages, conversations, conversation_tags, message_feedbacks).
 *
 * Design rules:
 *  - Every stat is scoped to one authenticated user's channels (and optionally
 *    narrowed to a specific business the user owns).
 *  - Aggregation is done in SQL (COUNT / AVG / GROUP BY) — we never pull the
 *    whole table into PHP.
 *  - A date `preset` is applied to the queries, so the numbers genuinely change
 *    with the selected range.
 *  - NULL confidence is excluded from the average; out-of-range confidence is
 *    normalized — never silently dropped or treated as 0.
 *  - NULL / absent detected_dialect is NOT counted as English; English is only
 *    reported from an explicit stored detected_language.
 *  - The dashboard still returns meaningful numbers with zero feedback records.
 */
class TrainingStatsService
{
    private User $user;
    private ?int $businessId;


    public function statistics(string $preset = 'last_30_days', ?int $businessId = null): array
    {
        $this->user = auth()->user();
        $this->businessId = $businessId;

        if ($businessId) {
            BusinessProfile::where('user_id', $this->user->id)
                ->where('id', $businessId)
                ->firstOrFail();
        }

        [$start, $end] = $this->resolveRange($preset);

        // ── AI messages scoped to this user (+ business), in range ──────────
        $aiMessages = $this->aiMessagesQuery($start, $end);
        $totalAiMessages = (clone $aiMessages)->count();

        // ── Confidence (NULL excluded, out-of-range normalized in SQL) ──────
        $conf = (clone $aiMessages)
            ->selectRaw('COUNT(confidence_score) AS with_conf')
            ->selectRaw('AVG(CASE WHEN confidence_score BETWEEN 0 AND 1 THEN confidence_score
                                    WHEN confidence_score > 1 AND confidence_score <= 100 THEN confidence_score / 100 END) AS avg_conf')
            ->first();

        // ── Recent windows (always relative to now, same user/business scope) ──
        $aiToday    = $this->aiMessagesQuery(null, null)->where('created_at', '>=', Carbon::now()->startOfDay())->count();
        $aiWeek     = $this->aiMessagesQuery(null, null)->where('created_at', '>=', Carbon::now()->startOfWeek())->count();
        $aiMonth    = $this->aiMessagesQuery(null, null)->where('created_at', '>=', Carbon::now()->startOfMonth())->count();

        // ── Conversations ──────────────────────────────────────────────────────
        $conversations      = $this->conversationsQuery($start, $end);
        $totalConversations = (clone $conversations)->count();

        $withAiReply = (clone $conversations)
            ->whereHas('messages', function ($q) {
                $q->where('is_ai', true)->where('direction', 'outbound');
            })
            ->count();

        // ── Escalations ────────────────────────────────────────────────────────
        $escalatedInRange = (clone $this->conversationsQuery($start, $end))
            ->where('requires_human', true)
            ->count();

        $escalatedToday  = $this->escalatedSince(Carbon::now()->startOfDay());
        $escalatedWeek   = $this->escalatedSince(Carbon::now()->startOfWeek());
        $escalatedMonth  = $this->escalatedSince(Carbon::now()->startOfMonth());

        $escalationReasons = (clone $this->conversationsQuery($start, $end))
            ->where('requires_human', true)
            ->whereNotNull('escalation_reason')
            ->selectRaw('escalation_reason, COUNT(*) AS count')
            ->groupBy('escalation_reason')
            ->pluck('count', 'escalation_reason')
            ->toArray();

        // ── Intent (per-message, from stored intent; falls back to tags) ──────
        $intentBreakdown = $this->intentBreakdown($aiMessages);

        // ── Channels (via join, grouped by channel type) ──────────────────────
        $channelBreakdown = $this->channelBreakdown($start, $end);

        // ── Dialects / language (never counts NULL as a dialect) ───────────────
        $dialectBreakdown = $this->dialectBreakdown($aiMessages, $totalAiMessages);

        // ── Feedback (may legitimately be empty) ───────────────────────────────
        $feedback = $this->feedbackStats($start, $end, $totalAiMessages);

        return [
            'range' => [
                'preset' => $preset,
                'start'  => $start?->toDateTimeString(),
                'end'    => $end?->toDateTimeString(),
            ],

            // AI messages
            'total_ai_messages'   => $totalAiMessages,
            'ai_messages_today'   => $aiToday,
            'ai_messages_this_week' => $aiWeek,
            'ai_messages_this_month' => $aiMonth,

            // Conversations
            'total_conversations'      => $totalConversations,
            'conversations_with_ai_reply' => $withAiReply,
            'auto_reply_rate'          => $totalConversations > 0
                ? round(($withAiReply / $totalConversations) * 100, 1)
                : null,

            // Confidence (0–100; null when there are no AI messages with confidence)
            'avg_confidence'   => $conf->avg_conf !== null ? round(((float) $conf->avg_conf) * 100, 1) : null,
            'confidence_count' => (int) $conf->with_conf,
            'confidence_total' => $totalAiMessages,

            // Escalation
            'escalated_conversations' => $escalatedInRange,
            'escalation_rate'         => $totalConversations > 0
                ? round(($escalatedInRange / $totalConversations) * 100, 1)
                : null,
            'escalations_today'   => $escalatedToday,
            'escalations_this_week' => $escalatedWeek,
            'escalations_this_month' => $escalatedMonth,
            'escalation_reasons'  => $this->cleanEscalationReasons($escalationReasons),

            // Breakdowns
            'intent_breakdown'    => $intentBreakdown,
            'channel_breakdown'   => $channelBreakdown,
            'dialect_breakdown'   => $dialectBreakdown,
            'issue_breakdown'     => $feedback['issue_breakdown'],

            // Feedback
            'feedback_total'        => $feedback['total'],
            'feedback_positive'     => $feedback['positive'],
            'feedback_negative'     => $feedback['negative'],
            'feedback_rate'         => $feedback['rate'],
            'satisfaction_percentage' => $feedback['satisfaction'],

            'last_updated' => now()->format('d M Y, H:i'),
        ];
    }

    /* ─────────────────────────────────────────────────────────────────────────── */

    private function aiMessagesQuery(?Carbon $start, ?Carbon $end)
    {
        return Message::query()
            ->where('is_ai', true)
            ->where('direction', 'outbound')
            ->whereHas('conversation.channel', function ($q) {
                $q->where('user_id', $this->user->id);
                if ($this->businessId) {
                    $q->where('business_id', $this->businessId);
                }
            })
            ->when($start, fn ($q) => $q->whereBetween('created_at', [$start, $end]));
    }

    private function conversationsQuery(?Carbon $start, ?Carbon $end)
    {
        return Conversation::query()
            ->whereHas('channel', function ($q) {
                $q->where('user_id', $this->user->id);
                if ($this->businessId) {
                    $q->where('business_id', $this->businessId);
                }
            })
            ->when($this->businessId, fn ($q) => $q->where('business_id', $this->businessId))
            ->when($start, fn ($q) => $q->whereBetween('created_at', [$start, $end]));
    }

    private function escalatedSince(?Carbon $since): int
    {
        return $this->conversationsQuery(null, null)
            ->where('requires_human', true)
            ->when($since, fn ($q) => $q->where('created_at', '>=', $since))
            ->count();
    }

    private function intentBreakdown($aiMessages): array
    {
        $byMessage = (clone $aiMessages)
            ->whereNotNull('intent')
            ->where('intent', '!=', '')
            ->selectRaw('intent, COUNT(*) AS count')
            ->groupBy('intent')
            ->pluck('count', 'intent')
            ->toArray();

        // Historical messages predate the per-message `intent` column. Fall back
        // to conversation_tags only when messages carry no intent at all, so we
        // never double-count a conversation that has both a tag and message intent.
        if (array_sum($byMessage) === 0) {
            return ConversationTag::query()
                ->whereHas('conversation.channel', function ($q) {
                    $q->where('user_id', $this->user->id);
                    if ($this->businessId) {
                        $q->where('business_id', $this->businessId);
                    }
                })
                ->whereNotNull('intent')
                ->selectRaw('intent, COUNT(*) AS count')
                ->groupBy('intent')
                ->pluck('count', 'intent')
                ->toArray();
        }

        return $byMessage;
    }

    private function channelBreakdown(?Carbon $start, ?Carbon $end): array
    {
        return Message::query()
            ->where('messages.is_ai', true)
            ->where('messages.direction', 'outbound')
            ->join('conversations', 'messages.conversation_id', '=', 'conversations.id')
            ->join('channels', 'conversations.channel_id', '=', 'channels.id')
            ->where('channels.user_id', $this->user->id)
            ->when($this->businessId, fn ($q) => $q->where('channels.business_id', $this->businessId))
            ->when($start, fn ($q) => $q->whereBetween('messages.created_at', [$start, $end]))
            ->selectRaw('channels.type, COUNT(*) AS count')
            ->groupBy('channels.type')
            ->pluck('count', 'channels.type')
            ->toArray();
    }

    private function dialectBreakdown($aiMessages, int $totalAiMessages): array
    {
        $dialectBuckets = (clone $aiMessages)
            ->whereNotNull('detected_dialect')
            ->selectRaw('detected_dialect, COUNT(*) AS count')
            ->groupBy('detected_dialect')
            ->pluck('count', 'detected_dialect')
            ->toArray();

        $langBuckets = (clone $aiMessages)
            ->whereNotNull('detected_language')
            ->selectRaw('detected_language, COUNT(*) AS count')
            ->groupBy('detected_language')
            ->pluck('count', 'detected_language')
            ->toArray();

        $classified = [];

        // Real Arabic dialect buckets only.
        foreach (['egyptian', 'gulf', 'msa'] as $d) {
            if (!empty($dialectBuckets[$d])) {
                $classified[$d] = (int) $dialectBuckets[$d];
            }
        }

        // "mixed" combines Arabic-mixed dialect with mixed-arabic/english text.
        $mixed = (int) ($dialectBuckets['mixed'] ?? 0) + (int) ($langBuckets['mixed'] ?? 0);
        if ($mixed > 0) {
            $classified['mixed'] = $mixed;
        }

        // English is only reported from an explicit stored language.
        if (!empty($langBuckets['english'])) {
            $classified['english'] = (int) $langBuckets['english'];
        }

        // Everything else (no dialect, null language, unclassified Arabic) is
        // genuinely "unknown" — never counted as a real dialect.
        $unknown = $totalAiMessages - array_sum($classified);
        if ($unknown > 0) {
            $classified['unknown'] = $unknown;
        }

        return $classified;
    }

    private function feedbackStats(?Carbon $start, ?Carbon $end, int $aiMessages): array
    {
        $query = MessageFeedback::query()
            ->whereHas('message.conversation.channel', function ($q) {
                $q->where('user_id', $this->user->id);
                if ($this->businessId) {
                    $q->where('business_id', $this->businessId);
                }
            })
            ->when($start, fn ($q) => $q->whereBetween('created_at', [$start, $end]));

        $total    = (clone $query)->count();
        $positive = (clone $query)->where('feedback', 'positive')->count();
        $negative = (clone $query)->where('feedback', 'negative')->count();

        $issueBreakdown = (clone $query)
            ->where('feedback', 'negative')
            ->whereNotNull('issue_type')
            ->selectRaw('issue_type, COUNT(*) AS count')
            ->groupBy('issue_type')
            ->pluck('count', 'issue_type')
            ->toArray();

        return [
            'total'    => $total,
            'positive' => $positive,
            'negative' => $negative,
            'rate'     => $aiMessages > 0 ? round(($total / $aiMessages) * 100, 1) : null,
            'satisfaction' => $total > 0 ? round(($positive / $total) * 100, 1) : null,
            'issue_breakdown' => $issueBreakdown,
        ];
    }

    private function cleanEscalationReasons(array $reasons): array
    {
        $cleaned = [];
        foreach ($reasons as $raw => $count) {
            $key = $this->cleanEscalationReason($raw);
            $cleaned[$key] = ((int) ($cleaned[$key] ?? 0)) + (int) $count;
        }
        arsort($cleaned);
        return $cleaned;
    }

    /**
     * Normalize an escalation_reason string into a short, stable label.
     * e.g. "ai_hard_escalation: complaint (intent=escalation, ...)" → "complaint"
     */
    private function cleanEscalationReason(string $reason): string
    {
        $s = trim($reason);

        // Drop trailing "(...)" context.
        if (($pos = strpos($s, '(')) !== false) {
            $s = trim(substr($s, 0, $pos));
        }

        // "prefix: reason" → reason only.
        if (($pos = strrpos($s, ':')) !== false) {
            $s = trim(substr($s, $pos + 1));
        }

        $s = trim(preg_replace('/\s+/', ' ', $s));

        return $s !== '' ? mb_substr($s, 0, 60) : 'escalated';
    }

    private function resolveRange(string $preset): array
    {
        $end = Carbon::now()->endOfDay();

        $start = match ($preset) {
            'today'         => Carbon::now()->startOfDay(),
            'last_7_days'   => Carbon::now()->subDays(7)->startOfDay(),
            'this_month'    => Carbon::now()->startOfMonth(),
            'last_30_days'  => Carbon::now()->subDays(30)->startOfDay(),
            'all_time'      => null,
            default         => Carbon::now()->subDays(30)->startOfDay(),
        };

        return [$start, $end];
    }
}