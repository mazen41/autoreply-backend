<?php

namespace App\Services;

use App\Models\AnalyticsDaily;
use App\Models\AiMetric;
use App\Models\CsatRating;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Subscription;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AnalyticsService
{
    /**
     * Calculate daily analytics for a business
     */
    public function calculateDailyAnalytics(int $businessId, ?string $date = null): void
    {
        [$date, $startDate, $endDate] = $this->businessDayRange($businessId, $date);

        // Get conversations for the day
        $conversations = Conversation::where('business_id', $businessId)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->get();

        // Get messages for the day
        $messages = Message::whereHas('conversation', fn ($query) => $query->where('business_id', $businessId))
            ->whereBetween('created_at', [$startDate, $endDate])
            ->get();

        // Calculate metrics
        $totalConversations = $conversations->count();
        $totalMessages = $messages->count();
        $aiMessages = $messages->where('is_ai', true)->count();
        $humanMessages = $messages->where('is_ai', false)->count();
        
        // Calculate average response time
        $avgResponseTime = $this->medianResponseTimeSeconds($businessId, $startDate, $endDate) ?? 0.0;

        // Count new vs returning users
        $newUsers = Conversation::where('business_id', $businessId)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->distinct('sender_id')
            ->count('sender_id');
        
        $returningUsers = Conversation::where('business_id', $businessId)
            ->where('created_at', '<', $startDate)
            ->where('last_message_at', '>=', $startDate)
            ->where('last_message_at', '<=', $endDate)
            ->distinct('sender_id')
            ->count('sender_id');

        // Calculate resolved conversations (conversations with no activity in last 24h)
        $resolvedConversations = $conversations
            ->where('last_message_at', '<', Carbon::now()->subHours(24))
            ->count();

        // Update or create daily analytics
        $daily = AnalyticsDaily::query()
            ->where('business_id', $businessId)
            ->whereDate('date', $date)
            ->first() ?? new AnalyticsDaily(['business_id' => $businessId, 'date' => $date]);
        $daily->fill([
            'total_conversations' => $totalConversations,
            'total_messages' => $totalMessages,
            'ai_messages' => $aiMessages,
            'human_messages' => $humanMessages,
            'avg_response_time_seconds' => round($avgResponseTime, 2),
            'new_users' => $newUsers,
            'returning_users' => $returningUsers,
            'resolved_conversations' => $resolvedConversations,
        ])->save();

        Log::info('Daily analytics calculated', [
            'business_id' => $businessId,
            'date' => $date,
        ]);
    }

    /**
     * Get revenue attribution report for a business.
     *
     * Categorizes revenue into:
     * - AI-Driven: Orders where conversation had ai_enabled=true and no human takeover
     * - Agent-Assisted: Orders where conversation had requires_human=true or assigned agent
     * - Direct: Orders without active messaging conversations
     *
     * @param int $businessId
     * @param string $startDate
     * @param string $endDate
     * @return array
     */
    public function getRevenueAttributionReport(int $businessId, string $startDate, string $endDate): array
    {
        $start = Carbon::parse($startDate)->startOfDay();
        $end = Carbon::parse($endDate)->endOfDay();

        // Get all conversations for the business in the date range
        $conversations = Conversation::where('business_id', $businessId)
            ->whereBetween('created_at', [$start, $end])
            ->get();

        $aiDrivenRevenue = 0;
        $agentAssistedRevenue = 0;
        $directRevenue = 0;
        $aiDrivenOrders = 0;
        $agentAssistedOrders = 0;
        $directOrders = 0;

        foreach ($conversations as $conversation) {
            $checkoutState = $conversation->checkout_state ?? [];
            $orderId = $checkoutState['order_id'] ?? null;
            $orderValue = $checkoutState['product_price'] ?? 0;

            if (!$orderId || !$orderValue) {
                continue;
            }

            // Check if this is AI-driven (ai_enabled=true, no human takeover)
            $isAiDriven = $conversation->ai_enabled
                && !$conversation->requires_human
                && !$conversation->assigned_agent_id;

            // Check if this is agent-assisted (requires_human=true or has assigned agent)
            $isAgentAssisted = $conversation->requires_human
                || $conversation->assigned_agent_id;

            if ($isAiDriven) {
                $aiDrivenRevenue += $orderValue;
                $aiDrivenOrders++;
            } elseif ($isAgentAssisted) {
                $agentAssistedRevenue += $orderValue;
                $agentAssistedOrders++;
            } else {
                $directRevenue += $orderValue;
                $directOrders++;
            }
        }

        $totalRevenue = $aiDrivenRevenue + $agentAssistedRevenue + $directRevenue;
        $totalOrders = $aiDrivenOrders + $agentAssistedOrders + $directOrders;

        return [
            'period' => [
                'start' => $startDate,
                'end' => $endDate,
            ],
            'summary' => [
                'total_revenue' => round($totalRevenue, 2),
                'total_orders' => $totalOrders,
                'average_order_value' => $totalOrders > 0 ? round($totalRevenue / $totalOrders, 2) : 0,
            ],
            'breakdown' => [
                'ai_driven' => [
                    'revenue' => round($aiDrivenRevenue, 2),
                    'orders' => $aiDrivenOrders,
                    'percentage' => $totalRevenue > 0 ? round(($aiDrivenRevenue / $totalRevenue) * 100, 1) : 0,
                    'aov' => $aiDrivenOrders > 0 ? round($aiDrivenRevenue / $aiDrivenOrders, 2) : 0,
                ],
                'agent_assisted' => [
                    'revenue' => round($agentAssistedRevenue, 2),
                    'orders' => $agentAssistedOrders,
                    'percentage' => $totalRevenue > 0 ? round(($agentAssistedRevenue / $totalRevenue) * 100, 1) : 0,
                    'aov' => $agentAssistedOrders > 0 ? round($agentAssistedRevenue / $agentAssistedOrders, 2) : 0,
                ],
                'direct' => [
                    'revenue' => round($directRevenue, 2),
                    'orders' => $directOrders,
                    'percentage' => $totalRevenue > 0 ? round(($directRevenue / $totalRevenue) * 100, 1) : 0,
                    'aov' => $directOrders > 0 ? round($directRevenue / $directOrders, 2) : 0,
                ],
            ],
        ];
    }

    /**
     * Calculate AI metrics for a business
     */
    public function calculateAiMetrics(int $businessId, ?string $date = null): void
    {
        [$date, $startDate, $endDate] = $this->businessDayRange($businessId, $date);

        // AiMetric is a per-request token/cost ledger (not a daily rollup
        // table). Calls are recorded when the AI request runs, so the daily
        // task validates/aggregates that ledger without writing incompatible
        // legacy columns back into it.
        $summary = AiMetric::where('business_profile_id', $businessId)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->selectRaw('COUNT(*) as requests, COALESCE(SUM(total_tokens), 0) as total_tokens, COALESCE(SUM(estimated_cost), 0) as estimated_cost')
            ->first();

        Log::info('AI usage metrics summarized', [
            'business_id' => $businessId,
            'date' => $date,
            'requests' => (int) ($summary->requests ?? 0),
            'total_tokens' => (int) ($summary->total_tokens ?? 0),
            'estimated_cost' => (float) ($summary->estimated_cost ?? 0),
        ]);
    }

    /**
     * Get CSAT score for a business
     */
    public function getCsatScore(int $businessId, int $days = 30, ?int $channelId = null, ?int $botId = null): array
    {
        $business = \App\Models\BusinessProfile::query()->findOrFail($businessId);
        $timezone = in_array($business->timezone, timezone_identifiers_list(), true)
            ? $business->timezone
            : config('app.timezone', 'UTC');
        $startDate = Carbon::now($timezone)->startOfDay()->subDays(max(1, min(365, $days)) - 1)->utc();
        
        $ratings = CsatRating::where('business_id', $businessId)
            ->where('rated_at', '>=', $startDate)
            ->when($channelId !== null || $botId !== null, function ($query) use ($channelId, $botId): void {
                $query->whereHas('conversation', function ($conversations) use ($channelId, $botId): void {
                    $conversations
                        ->when($channelId !== null, fn ($builder) => $builder->where('channel_id', $channelId))
                        ->when($botId !== null, fn ($builder) => $builder->where('bot_id', $botId));
                });
            })
            ->get();

        $totalRatings = $ratings->count();
        $positiveRatings = $ratings->where('rating', 'positive')->count();
        
        $csatScore = $totalRatings > 0
            ? round(($positiveRatings / $totalRatings) * 100, 2)
            : null;

        return [
            'csat_score' => $csatScore,
            'total_ratings' => $totalRatings,
            'positive_ratings' => $positiveRatings,
            'negative_ratings' => $totalRatings - $positiveRatings,
            'days' => $days,
        ];
    }

    /**
     * Generate daily report for a business
     */
    public function generateDailyReport(int $businessId, ?string $date = null): array
    {
        [$date] = $this->businessDayRange($businessId, $date);
        
        // Calculate both daily analytics and AI metrics
        $this->calculateDailyAnalytics($businessId, $date);
        $this->calculateAiMetrics($businessId, $date);
        
        // Get CSAT score for the last 30 days
        $csatData = $this->getCsatScore($businessId, 30);
        
        return [
            'business_id' => $businessId,
            'date' => $date,
            'csat_score' => $csatData['csat_score'],
            'total_ratings' => $csatData['total_ratings'],
            'generated_at' => Carbon::now()->toIso8601String(),
        ];
    }

    /** Business dates are local calendar dates; stored timestamps are queried in UTC. */
    private function businessDayRange(int $businessId, ?string $date = null): array
    {
        $business = \App\Models\BusinessProfile::query()->findOrFail($businessId);
        $timezone = in_array($business->timezone, timezone_identifiers_list(), true)
            ? $business->timezone
            : config('app.timezone', 'UTC');
        $date ??= Carbon::now($timezone)->subDay()->toDateString();
        $localStart = Carbon::parse($date, $timezone)->startOfDay();

        return [
            $localStart->toDateString(),
            $localStart->copy()->utc(),
            $localStart->copy()->endOfDay()->utc(),
        ];
    }

    /** The median first human-or-AI reply latency for inbound customer messages in the range. */
    public function medianResponseTimeSeconds(int $businessId, \DateTimeInterface $start, \DateTimeInterface $end, ?int $channelId = null, ?int $botId = null): ?float
    {
        $firstInbound = DB::table('messages as inbound')
            ->join('conversations', 'conversations.id', '=', 'inbound.conversation_id')
            ->where('conversations.business_id', $businessId)
            ->when($channelId !== null, fn ($query) => $query->where('conversations.channel_id', $channelId))
            ->when($botId !== null, fn ($query) => $query->where('conversations.bot_id', $botId))
            ->where('inbound.direction', 'inbound')
            ->whereBetween('inbound.created_at', [$start, $end])
            ->select('inbound.id as inbound_id', 'inbound.conversation_id', 'inbound.created_at as inbound_at');

        $firstReply = DB::table('messages as outbound')
            ->joinSub($firstInbound, 'inbound', 'inbound.conversation_id', '=', 'outbound.conversation_id')
            ->where('outbound.direction', 'outbound')
            ->whereColumn('outbound.created_at', '>', 'inbound.inbound_at')
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')->from('messages as next_inbound')
                    ->whereColumn('next_inbound.conversation_id', 'inbound.conversation_id')
                    ->where('next_inbound.direction', 'inbound')
                    ->whereColumn('next_inbound.created_at', '>', 'inbound.inbound_at')
                    ->whereColumn('next_inbound.created_at', '<', 'outbound.created_at');
            })
            ->select('inbound.inbound_id')
            ->selectRaw('MIN(outbound.created_at) AS first_reply_at')
            ->groupBy('inbound.inbound_id');

        $driver = DB::connection()->getDriverName();
        $durationSql = $driver === 'sqlite'
            ? '((julianday(replies.first_reply_at) - julianday(eligible_inbound.inbound_at)) * 86400)'
            : 'TIMESTAMPDIFF(SECOND, eligible_inbound.inbound_at, replies.first_reply_at)';

        $latencies = DB::table('messages as inbound')
            ->joinSub($firstInbound, 'eligible_inbound', 'eligible_inbound.inbound_id', '=', 'inbound.id')
            ->joinSub($firstReply, 'replies', 'replies.inbound_id', '=', 'inbound.id')
            ->selectRaw("{$durationSql} AS latency_seconds")
            ->orderBy('latency_seconds');
        $count = (clone $latencies)->count();
        if ($count === 0) {
            return null;
        }

        $middle = intdiv($count - 1, 2);
        $take = $count % 2 === 0 ? 2 : 1;
        $median = $latencies->offset($middle)->limit($take)->pluck('latency_seconds');

        return (float) $median->avg();
    }
}
