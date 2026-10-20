<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AnalyticsService;
use App\Services\BillingService;
use App\Models\CsatRating;
use App\Models\AnalyticsDaily;
use App\Models\AiMetric;
use App\Models\Subscription;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Channel;
use App\Services\BusinessAccessService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Carbon\Carbon;

class AnalyticsController extends Controller
{
    private $analyticsService;

    public function __construct(AnalyticsService $analyticsService)
    {
        $this->analyticsService = $analyticsService;
    }

    /**
     * Unified analytics dashboard for a business.
     *
     * Real, DB-backed aggregation over conversations/messages for the
     * requested date range. Intentionally omits sections (agents,
     * channels breakdown, ecommerce, workflows) that don't yet have a
     * reliable, real data source — the frontend hides those cards when
     * the corresponding key is absent, so we never show fabricated data.
     */
    public function getDashboard(Request $request, $businessId)
    {
        $business = app(BusinessAccessService::class)->authorize($request->user(), $businessId, 'read');
        $filters = $request->validate([
            'preset' => ['sometimes', 'string', Rule::in(['today', 'yesterday', 'last_7_days', 'this_month', 'last_30_days'])],
            'channel_id' => ['nullable', 'integer', Rule::exists('channels', 'id')->where('business_id', $businessId)],
            'bot_id' => ['nullable', 'integer', Rule::exists('bots', 'id')->where('business_profile_id', $businessId)],
        ]);
        $timezone = in_array($business->timezone, timezone_identifiers_list(), true) ? $business->timezone : 'UTC';
        [$startDate, $endDate, $rangeStartLocal, $rangeEndLocal] = $this->resolveDateRangeFromPreset(
            $filters['preset'] ?? 'last_30_days',
            $timezone
        );

        $applyConversationFilters = static function ($query) use ($businessId, $filters): void {
            $query->where('business_id', $businessId)
                ->when(isset($filters['channel_id']), fn ($builder) => $builder->where('channel_id', $filters['channel_id']))
                ->when(isset($filters['bot_id']), fn ($builder) => $builder->where('bot_id', $filters['bot_id']));
        };
        $conversationsInRange = Conversation::query();
        $applyConversationFilters($conversationsInRange);
        $conversationsInRange->whereBetween('created_at', [$startDate, $endDate]);

        $newConversations = (clone $conversationsInRange)->count();
        $totalConversations = $newConversations;
        $openConversations = (clone $conversationsInRange)->where('status', 'open')->count();
        $closedConversations = (clone $conversationsInRange)->where('status', 'closed')->count();
        $escalatedQuery = Conversation::query();
        $applyConversationFilters($escalatedQuery);
        $escalatedConversations = $escalatedQuery->whereNotNull('escalated_at')
            ->whereBetween('escalated_at', [$startDate, $endDate])->count();

        $messagesInRange = Message::whereHas('conversation', function ($query) use ($applyConversationFilters) {
                $applyConversationFilters($query);
            })
            ->whereBetween('created_at', [$startDate, $endDate]);

        $automatedSources = ['campaign', 'email_campaign', 'sequence', 'automation', 'proactive'];
        $aiResponses = (clone $messagesInRange)->where('direction', 'outbound')->where('is_ai', true)
            ->where(fn ($query) => $query->whereNull('source')->orWhereNotIn('source', $automatedSources))->count();
        $totalOutbound = (clone $messagesInRange)->where('direction', 'outbound')
            ->where(fn ($query) => $query->whereNull('source')->orWhereNotIn('source', $automatedSources))->count();
        $aiConversationsQuery = Conversation::query();
        $applyConversationFilters($aiConversationsQuery);
        $aiConversationsCount = $aiConversationsQuery->whereBetween('created_at', [$startDate, $endDate])
            ->whereHas('messages', fn ($query) => $query->where('direction', 'outbound')->where('is_ai', true)
                ->where(fn ($source) => $source->whereNull('source')->orWhereNotIn('source', $automatedSources))
                ->whereBetween('created_at', [$startDate, $endDate]))
            ->count();

        $aiShareOfReplies = $totalOutbound > 0 ? ($aiResponses / $totalOutbound) * 100 : 0;

        // Average response time: first outbound reply after each conversation's first inbound message.
        $medianResponseMinutes = $this->medianResponseTimeMinutes(
            $businessId,
            $startDate,
            $endDate,
            isset($filters['channel_id']) ? (int) $filters['channel_id'] : null,
            isset($filters['bot_id']) ? (int) $filters['bot_id'] : null,
        );

        return response()->json([
            'range' => ['start' => $rangeStartLocal, 'end' => $rangeEndLocal, 'timezone' => $timezone],
            'filters' => [
                'channel_id' => isset($filters['channel_id']) ? (int) $filters['channel_id'] : null,
                'bot_id' => isset($filters['bot_id']) ? (int) $filters['bot_id'] : null,
            ],
            'conversations' => [
                'total' => $totalConversations,
                'new' => $newConversations,
                'open' => $openConversations,
                'closed' => $closedConversations,
                'median_response_time_minutes' => $medianResponseMinutes,
                'avg_response_time_minutes' => $medianResponseMinutes, // deprecated compatibility alias
                'avg_resolution_time_minutes' => null, // no resolution timestamp tracked yet — omitted rather than faked
            ],
            'ai' => [
                'ai_conversations' => $aiConversationsCount,
                'ai_responses' => $aiResponses,
                'escalated_conversations' => $escalatedConversations,
                'ai_share_of_replies' => round($aiShareOfReplies, 1),
                'ai_success_rate' => round($aiShareOfReplies, 1), // deprecated compatibility alias
            ],
        ]);
    }

    private function resolveDateRangeFromPreset(string $preset, string $timezone = 'UTC'): array
    {
        $now = Carbon::now($timezone);
        $endLocal = $now->copy()->endOfDay();
        $startLocal = match ($preset) {
            'today' => $now->copy()->startOfDay(),
            'yesterday' => $now->copy()->subDay()->startOfDay(),
            'last_7_days' => $now->copy()->startOfDay()->subDays(6),
            'this_month' => $now->copy()->startOfMonth(),
            'last_30_days' => $now->copy()->startOfDay()->subDays(29),
            default => $now->copy()->startOfDay()->subDays(29),
        };
        if ($preset === 'yesterday') {
            $endLocal = $now->copy()->subDay()->endOfDay();
        }
        return [$startLocal->copy()->utc(), $endLocal->copy()->utc(), $startLocal->toDateString(), $endLocal->toDateString()];
    }

    private function medianResponseTimeMinutes(int $businessId, Carbon $start, Carbon $end, ?int $channelId = null, ?int $botId = null): ?float
    {
        $seconds = $this->analyticsService->medianResponseTimeSeconds($businessId, $start, $end, $channelId, $botId);
        return $seconds === null ? null : round($seconds / 60, 1);
    }

    /**
     * Get CSAT score for a business
     */
    public function getCsatScore(Request $request, $businessId)
    {
        app(BusinessAccessService::class)->authorize($request->user(), $businessId, 'read');
        $filters = $request->validate([
            'days' => 'sometimes|integer|min:1|max:365',
            'channel_id' => ['nullable', 'integer', Rule::exists('channels', 'id')->where('business_id', $businessId)],
            'bot_id' => ['nullable', 'integer', Rule::exists('bots', 'id')->where('business_profile_id', $businessId)],
        ]);

        $csatData = $this->analyticsService->getCsatScore(
            (int) $businessId,
            (int) ($filters['days'] ?? 30),
            isset($filters['channel_id']) ? (int) $filters['channel_id'] : null,
            isset($filters['bot_id']) ? (int) $filters['bot_id'] : null,
        );

        return response()->json($csatData);
    }

    /**
     * Get daily analytics for a business
     */
    public function getDailyAnalytics(Request $request, $businessId)
    {
        app(BusinessAccessService::class)->authorize($request->user(), $businessId, 'read');
        $validated = $request->validate([
            'start_date' => 'sometimes|date_format:Y-m-d',
            'end_date' => 'sometimes|date_format:Y-m-d|after_or_equal:start_date',
        ]);
        $startDate = $validated['start_date'] ?? null;
        $endDate = $validated['end_date'] ?? null;

        $query = AnalyticsDaily::where('business_id', $businessId);

        if ($startDate && $endDate) {
            $query->forDateRange($startDate, $endDate);
        }

        $analytics = $query->orderBy('date', 'desc')->get();

        return response()->json($analytics);
    }

    /**
     * Get AI metrics for a business
     */
    public function getAiMetrics(Request $request, $businessId)
    {
        $business = app(BusinessAccessService::class)->authorize($request->user(), $businessId, 'read');
        $validated = $request->validate([
            'start_date' => 'sometimes|date_format:Y-m-d',
            'end_date' => 'sometimes|date_format:Y-m-d|after_or_equal:start_date',
        ]);
        $startDate = $validated['start_date'] ?? null;
        $endDate = $validated['end_date'] ?? null;

        $query = AiMetric::where('business_profile_id', $businessId);

        if ($startDate && $endDate) {
            $timezone = in_array($business->timezone, timezone_identifiers_list(), true) ? $business->timezone : 'UTC';
            $query->whereBetween('created_at', [
                Carbon::parse($startDate, $timezone)->startOfDay()->utc(),
                Carbon::parse($endDate, $timezone)->endOfDay()->utc(),
            ]);
        }

        $metrics = $query->orderBy('created_at', 'desc')->get();

        return response()->json($metrics);
    }

    /**
     * Get recent CSAT ratings
     */
    public function getRecentRatings(Request $request, $businessId)
    {
        app(BusinessAccessService::class)->authorize($request->user(), $businessId, 'read');
        $limit = $request->validate(['limit' => 'sometimes|integer|min:1|max:100'])['limit'] ?? 20;

        $ratings = CsatRating::where('business_id', $businessId)
            ->with(['conversation', 'user'])
            ->orderBy('rated_at', 'desc')
            ->limit($limit)
            ->get();

        return response()->json($ratings);
    }

    /**
     * Manually trigger analytics calculation
     */
    public function calculateAnalytics(Request $request, $businessId)
    {
        app(BusinessAccessService::class)->authorize($request->user(), $businessId, 'write');
        $request->validate([
            'date' => 'nullable|date',
        ]);

        $date = $request->date ?? null;

        $this->analyticsService->calculateDailyAnalytics($businessId, $date);
        $this->analyticsService->calculateAiMetrics($businessId, $date);

        return response()->json(['success' => true]);
    }
}
