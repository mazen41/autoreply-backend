<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AnalyticsService;
use App\Services\BillingService;
use App\Models\CsatRating;
use App\Models\AnalyticsDaily;
use App\Models\AiMetric;
use App\Models\Subscription;
use App\Models\BusinessProfile;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Channel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
        // Enforce business_id ownership — same pattern used elsewhere in the codebase.
        BusinessProfile::where('user_id', Auth::id())->findOrFail($businessId);

        [$startDate, $endDate] = $this->resolveDateRangeFromPreset($request->query('preset', 'last_30_days'));

        $conversationsInRange = Conversation::where('business_id', $businessId)
            ->whereBetween('created_at', [$startDate, $endDate]);

        $totalConversations = Conversation::where('business_id', $businessId)->count();
        $newConversations = (clone $conversationsInRange)->count();
        $openConversations = Conversation::where('business_id', $businessId)->where('status', 'open')->count();
        $closedConversations = Conversation::where('business_id', $businessId)->where('status', 'closed')->count();
        $escalatedConversations = Conversation::where('business_id', $businessId)
            ->whereNotNull('escalated_at')
            ->whereBetween('escalated_at', [$startDate, $endDate])
            ->count();

        $messagesInRange = Message::whereHas('conversation', function ($q) use ($businessId) {
                $q->where('business_id', $businessId);
            })
            ->whereBetween('created_at', [$startDate, $endDate]);

        $aiResponses = (clone $messagesInRange)->where('is_ai', true)->count();
        $totalOutbound = (clone $messagesInRange)->where('direction', 'outbound')->count();
        $aiConversationsCount = Conversation::where('business_id', $businessId)
            ->where('ai_enabled', true)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->count();

        $aiSuccessRate = $totalOutbound > 0 ? ($aiResponses / $totalOutbound) * 100 : 0;

        // Average response time: first outbound reply after each conversation's first inbound message.
        $avgResponseMinutes = $this->averageResponseTimeMinutes($businessId, $startDate, $endDate);

        return response()->json([
            'range' => ['start' => $startDate->toDateString(), 'end' => $endDate->toDateString()],
            'conversations' => [
                'total' => $totalConversations,
                'new' => $newConversations,
                'open' => $openConversations,
                'closed' => $closedConversations,
                'avg_response_time_minutes' => $avgResponseMinutes,
                'avg_resolution_time_minutes' => null, // no resolution timestamp tracked yet — omitted rather than faked
            ],
            'ai' => [
                'ai_conversations' => $aiConversationsCount,
                'ai_responses' => $aiResponses,
                'escalated_conversations' => $escalatedConversations,
                'ai_success_rate' => round($aiSuccessRate, 1),
            ],
        ]);
    }

    private function resolveDateRangeFromPreset(string $preset): array
    {
        $end = Carbon::now()->endOfDay();
        $start = match ($preset) {
            'today' => Carbon::now()->startOfDay(),
            'yesterday' => Carbon::yesterday()->startOfDay(),
            'last_7_days' => Carbon::now()->subDays(7)->startOfDay(),
            'this_month' => Carbon::now()->startOfMonth(),
            'last_30_days' => Carbon::now()->subDays(30)->startOfDay(),
            default => Carbon::now()->subDays(30)->startOfDay(),
        };
        if ($preset === 'yesterday') {
            $end = Carbon::yesterday()->endOfDay();
        }
        return [$start, $end];
    }

    private function averageResponseTimeMinutes(int $businessId, Carbon $start, Carbon $end): ?float
    {
        $conversations = Conversation::where('business_id', $businessId)
            ->whereBetween('created_at', [$start, $end])
            ->with(['messages' => function ($q) {
                $q->orderBy('created_at', 'asc')->select('id', 'conversation_id', 'direction', 'created_at');
            }])
            ->get();

        $diffs = [];
        foreach ($conversations as $conversation) {
            $firstInbound = $conversation->messages->firstWhere('direction', 'inbound');
            if (!$firstInbound) {
                continue;
            }
            $firstReply = $conversation->messages
                ->where('direction', 'outbound')
                ->where('created_at', '>', $firstInbound->created_at)
                ->sortBy('created_at')
                ->first();
            if ($firstReply) {
                $diffs[] = $firstInbound->created_at->diffInMinutes($firstReply->created_at);
            }
        }

        if (empty($diffs)) {
            return null;
        }

        return round(array_sum($diffs) / count($diffs), 1);
    }

    /**
     * Get CSAT score for a business
     */
    public function getCsatScore(Request $request, $businessId)
    {
        $days = $request->query('days', 30);
        
        $csatData = $this->analyticsService->getCsatScore($businessId, $days);

        return response()->json($csatData);
    }

    /**
     * Get daily analytics for a business
     */
    public function getDailyAnalytics(Request $request, $businessId)
    {
        $startDate = $request->query('start_date');
        $endDate = $request->query('end_date');

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
        $startDate = $request->query('start_date');
        $endDate = $request->query('end_date');

        $query = AiMetric::where('business_id', $businessId);

        if ($startDate && $endDate) {
            $query->forDateRange($startDate, $endDate);
        }

        $metrics = $query->orderBy('date', 'desc')->get();

        return response()->json($metrics);
    }

    /**
     * Get recent CSAT ratings
     */
    public function getRecentRatings(Request $request, $businessId)
    {
        $limit = $request->query('limit', 20);

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
        $request->validate([
            'date' => 'nullable|date',
        ]);

        $date = $request->date ?? null;

        $this->analyticsService->calculateDailyAnalytics($businessId, $date);
        $this->analyticsService->calculateAiMetrics($businessId, $date);

        return response()->json(['success' => true]);
    }
}
