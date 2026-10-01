<?php

namespace App\Services;

use App\Models\AnalyticsDaily;
use App\Models\AiMetric;
use App\Models\CsatRating;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Subscription;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class AnalyticsService
{
    /**
     * Calculate daily analytics for a business
     */
    public function calculateDailyAnalytics(int $businessId, string $date = null): void
    {
        $date = $date ?? Carbon::yesterday()->toDateString();
        
        $startDate = Carbon::parse($date)->startOfDay();
        $endDate = Carbon::parse($date)->endOfDay();

        // Get conversations for the day
        $conversations = Conversation::whereHas('channel', function ($q) use ($businessId) {
                $q->where('user_id', function ($query) use ($businessId) {
                    $query->whereHas('business', function ($q) use ($businessId) {
                        $q->where('id', $businessId);
                    });
                });
            })
            ->whereBetween('created_at', [$startDate, $endDate])
            ->get();

        // Get messages for the day
        $messages = Message::whereHas('conversation.channel', function ($q) use ($businessId) {
                $q->where('user_id', function ($query) use ($businessId) {
                    $query->whereHas('business', function ($q) use ($businessId) {
                        $q->where('id', $businessId);
                    });
                });
            })
            ->whereBetween('created_at', [$startDate, $endDate])
            ->get();

        // Calculate metrics
        $totalConversations = $conversations->count();
        $totalMessages = $messages->count();
        $aiMessages = $messages->where('is_ai', true)->count();
        $humanMessages = $messages->where('is_ai', false)->count();
        
        // Calculate average response time
        $responseTimes = [];
        foreach ($conversations as $conversation) {
            $firstMessage = $conversation->messages()->orderBy('created_at')->first();
            $firstReply = $conversation->messages()
                ->where('direction', 'outbound')
                ->where('is_ai', true)
                ->orderBy('created_at')
                ->first();
            
            if ($firstMessage && $firstReply) {
                $responseTime = $firstReply->created_at->diffInSeconds($firstMessage->created_at);
                $responseTimes[] = $responseTime;
            }
        }
        
        $avgResponseTime = count($responseTimes) > 0 
            ? array_sum($responseTimes) / count($responseTimes) 
            : 0;

        // Count new vs returning users
        $newUsers = $conversations->where('created_at', '>=', $startDate)
            ->distinct('sender_id')
            ->count();
        
        $returningUsers = $conversations->where('created_at', '<', $startDate)
            ->where('last_message_at', '>=', $startDate)
            ->distinct('sender_id')
            ->count();

        // Calculate resolved conversations (conversations with no activity in last 24h)
        $resolvedConversations = $conversations
            ->where('last_message_at', '<', Carbon::now()->subHours(24))
            ->count();

        // Update or create daily analytics
        AnalyticsDaily::updateOrCreate(
            [
                'business_id' => $businessId,
                'date' => $date,
            ],
            [
                'total_conversations' => $totalConversations,
                'total_messages' => $totalMessages,
                'ai_messages' => $aiMessages,
                'human_messages' => $humanMessages,
                'avg_response_time_seconds' => round($avgResponseTime, 2),
                'new_users' => $newUsers,
                'returning_users' => $returningUsers,
                'resolved_conversations' => $resolvedConversations,
            ]
        );

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
    public function calculateAiMetrics(int $businessId, string $date = null): void
    {
        $date = $date ?? Carbon::yesterday()->toDateString();
        
        $startDate = Carbon::parse($date)->startOfDay();
        $endDate = Carbon::parse($date)->endOfDay();

        // Get AI messages for the day
        $aiMessages = Message::whereHas('conversation.channel', function ($q) use ($businessId) {
                $q->where('user_id', function ($query) use ($businessId) {
                    $query->whereHas('business', function ($q) use ($businessId) {
                        $q->where('id', $businessId);
                    });
                });
            })
            ->where('is_ai', true)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->get();

        $totalAiMessages = $aiMessages->count();
        $successfulAiMessages = $aiMessages->where('send_status', 'sent')->count();
        $escalatedMessages = $aiMessages->whereHas('conversation', function ($q) {
            $q->where('requires_human', true);
        })->count();

        // Calculate average confidence score
        $confidenceScores = $aiMessages->whereNotNull('confidence_score')
            ->pluck('confidence_score')
            ->toArray();
        
        $avgConfidence = count($confidenceScores) > 0 
            ? array_sum($confidenceScores) / count($confidenceScores) 
            : 0;

        // Get feedback from MessageFeedback
        $feedback = \App\Models\MessageFeedback::whereHas('message.conversation.channel', function ($q) use ($businessId) {
                $q->where('user_id', function ($query) use ($businessId) {
                    $query->whereHas('business', function ($q) use ($businessId) {
                        $q->where('id', $businessId);
                    });
                });
            })
            ->whereBetween('created_at', [$startDate, $endDate])
            ->get();

        $positiveFeedback = $feedback->where('feedback', 'positive')->count();
        $negativeFeedback = $feedback->where('feedback', 'negative')->count();

        // Calculate success rate
        $successRate = $totalAiMessages > 0 
            ? round(($successfulAiMessages / $totalAiMessages) * 100, 2) 
            : 0;

        // Update or create AI metrics
        AiMetric::updateOrCreate(
            [
                'business_id' => $businessId,
                'date' => $date,
            ],
            [
                'total_ai_messages' => $totalAiMessages,
                'successful_ai_messages' => $successfulAiMessages,
                'escalated_messages' => $escalatedMessages,
                'avg_confidence_score' => round($avgConfidence, 2),
                'positive_feedback' => $positiveFeedback,
                'negative_feedback' => $negativeFeedback,
                'success_rate' => $successRate,
            ]
        );

        Log::info('AI metrics calculated', [
            'business_id' => $businessId,
            'date' => $date,
        ]);
    }

    /**
     * Get CSAT score for a business
     */
    public function getCsatScore(int $businessId, int $days = 30): array
    {
        $startDate = Carbon::now()->subDays($days)->startOfDay();
        
        $ratings = CsatRating::where('business_id', $businessId)
            ->where('rated_at', '>=', $startDate)
            ->get();

        $totalRatings = $ratings->count();
        $positiveRatings = $ratings->where('rating', 'positive')->count();
        
        $csatScore = $totalRatings > 0 
            ? round(($positiveRatings / $totalRatings) * 100, 2) 
            : 0;

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
    public function generateDailyReport(int $businessId, string $date = null): array
    {
        $date = $date ?? Carbon::yesterday()->toDateString();
        
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
}
