<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AnalyticsService;
use App\Services\BillingService;
use App\Models\CsatRating;
use App\Models\AnalyticsDaily;
use App\Models\AiMetric;
use App\Models\Subscription;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AnalyticsController extends Controller
{
    private $analyticsService;

    public function __construct(AnalyticsService $analyticsService)
    {
        $this->analyticsService = $analyticsService;
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
