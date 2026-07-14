<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Channel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ReportsController extends Controller
{
    /**
     * Get daily message counts for the current user
     */
    public function dailyMessages(Request $request)
    {
        $user = Auth::user();
        $days = $request->get('days', 30); // Default to last 30 days
        
        $data = Message::where('user_id', $user->id)
            ->where('created_at', '>=', now()->subDays($days))
            ->select(DB::raw('DATE(created_at) as date'), DB::raw('COUNT(*) as count'))
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->pluck('count', 'date')
            ->toArray();

        // Fill in missing days with 0
        $result = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = now()->subDays($i)->format('Y-m-d');
            $result[] = $data[$date] ?? 0;
        }

        return response()->json([
            'data' => $result,
            'total' => array_sum($result),
        ]);
    }

    /**
     * Get message breakdown by channel
     */
    public function channelBreakdown(Request $request)
    {
        $user = Auth::user();
        
        $channels = Channel::where('user_id', $user->id)
            ->withCount('messages')
            ->get()
            ->map(function ($channel) {
                return [
                    'id' => $channel->id,
                    'type' => $channel->type,
                    'name' => $channel->name ?? $channel->type,
                    'messages_count' => $channel->messages_count,
                ];
            });

        return response()->json([
            'channels' => $channels,
            'total' => $channels->sum('messages_count'),
        ]);
    }

    /**
     * Get AI performance metrics
     */
    public function aiPerformance(Request $request)
    {
        $user = Auth::user();
        
        $totalMessages = Message::where('user_id', $user->id)->count();
        $autoReplies = Message::where('user_id', $user->id)
            ->where('is_ai_generated', true)
            ->count();
        
        $manualInterventions = Message::where('user_id', $user->id)
            ->where('is_ai_generated', false)
            ->where('direction', 'outgoing')
            ->count();

        // Calculate average response time (in seconds)
        $avgResponseTime = Message::where('user_id', $user->id)
            ->whereNotNull('response_time_seconds')
            ->avg('response_time_seconds') ?? 0;

        $responseRate = $totalMessages > 0 ? ($autoReplies / $totalMessages) * 100 : 0;

        return response()->json([
            'total_messages' => $totalMessages,
            'auto_replies' => $autoReplies,
            'auto_reply_rate' => round($responseRate, 1),
            'manual_interventions' => $manualInterventions,
            'avg_response_time_seconds' => round($avgResponseTime, 1),
            'avg_response_time_formatted' => $avgResponseTime < 60 
                ? round($avgResponseTime, 1) . 's' 
                : round($avgResponseTime / 60, 1) . 'm',
        ]);
    }

    /**
     * Get top questions from conversations
     */
    public function topQuestions(Request $request)
    {
        $user = Auth::user();
        $limit = $request->get('limit', 10);
        
        // Extract questions from incoming messages (simple heuristic: messages ending with ?)
        $questions = Message::where('user_id', $user->id)
            ->where('direction', 'incoming')
            ->where('body', 'like', '%?')
            ->select(DB::raw('LOWER(body) as question'), DB::raw('COUNT(*) as count'))
            ->groupBy('question')
            ->orderByDesc('count')
            ->limit($limit)
            ->get();

        return response()->json([
            'questions' => $questions,
        ]);
    }

    /**
     * Get time saved metrics
     */
    public function timeSaved(Request $request)
    {
        $user = Auth::user();
        
        $autoReplies = Message::where('user_id', $user->id)
            ->where('is_ai_generated', true)
            ->count();

        // Assume manual reply takes 3 minutes on average
        $manualReplyTimeMinutes = 3;
        $totalTimeSavedMinutes = $autoReplies * $manualReplyTimeMinutes;
        $totalTimeSavedHours = round($totalTimeSavedMinutes / 60, 1);

        // Estimate value (assuming $100/hour)
        $hourlyRate = 100;
        $estimatedValue = round($totalTimeSavedHours * $hourlyRate);

        return response()->json([
            'messages_handled' => $autoReplies,
            'avg_manual_reply_time' => $manualReplyTimeMinutes . ' ' . ($request->get('lang') === 'ar' ? 'دقائق' : 'min'),
            'time_saved_hours' => $totalTimeSavedHours,
            'estimated_value' => $estimatedValue,
            'estimated_value_formatted' => $estimatedValue . ' ' . ($request->get('lang') === 'ar' ? 'ريال' : 'SAR'),
        ]);
    }

    /**
     * Get comprehensive report summary
     */
    public function summary(Request $request)
    {
        $user = Auth::user();
        
        return response()->json([
            'daily_messages' => $this->dailyMessages($request)->getData(true),
            'channel_breakdown' => $this->channelBreakdown($request)->getData(true),
            'ai_performance' => $this->aiPerformance($request)->getData(true),
            'top_questions' => $this->topQuestions($request)->getData(true),
            'time_saved' => $this->timeSaved($request)->getData(true),
        ]);
    }

    /**
     * Get top-level dashboard stats (used by the main dashboard home page)
     */
    public function dashboardStats(Request $request)
    {
        $user = Auth::user();

        $totalMessages = Message::where('user_id', $user->id)->count();
        $aiReplies = Message::where('user_id', $user->id)
            ->where('is_ai_generated', true)
            ->count();
        $responseRate = $totalMessages > 0 ? round(($aiReplies / $totalMessages) * 100, 1) : 0;

        // Time saved: assume 3 minutes saved per AI-handled reply
        $hoursSaved = round(($aiReplies * 3) / 60, 1);

        // Week-over-week message trend
        $thisWeek = Message::where('user_id', $user->id)
            ->where('created_at', '>=', now()->subDays(7))
            ->count();
        $lastWeek = Message::where('user_id', $user->id)
            ->whereBetween('created_at', [now()->subDays(14), now()->subDays(7)])
            ->count();
        $messagesTrend = null;
        if ($lastWeek > 0) {
            $change = round((($thisWeek - $lastWeek) / $lastWeek) * 100, 1);
            $messagesTrend = ['value' => abs($change), 'isPositive' => $change >= 0];
        }

        // Top question this week (simple heuristic: incoming messages ending in '?')
        $topQuestionRow = Message::where('user_id', $user->id)
            ->where('direction', 'incoming')
            ->where('created_at', '>=', now()->subDays(7))
            ->where('body', 'like', '%?')
            ->select(DB::raw('body'), DB::raw('COUNT(*) as count'))
            ->groupBy('body')
            ->orderByDesc('count')
            ->first();

        return response()->json([
            'total_messages' => $totalMessages,
            'ai_replies' => $aiReplies,
            'response_rate' => $responseRate,
            'hours_saved' => $hoursSaved,
            'google_rating' => null,
            'messages_trend' => $messagesTrend,
            'rating_trend' => null,
            'top_question' => $topQuestionRow->body ?? null,
            'question_count' => $topQuestionRow->count ?? 0,
        ]);
    }
}
