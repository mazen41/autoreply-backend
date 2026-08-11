<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Models\MessageFeedback;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class FeedbackController extends Controller
{
    /**
     * Submit feedback for an AI message
     */
    public function submit(Request $request, $messageId)
    {
        $request->validate([
            'feedback' => 'required|in:positive,negative',
            'comment' => 'nullable|string|max:500',
            'issue_type' => 'nullable|in:inaccurate,inappropriate,off_topic,poor_quality,other',
        ]);

        $message = Message::whereHas('conversation.channel', function ($q) {
                $q->where('user_id', Auth::id());
            })
            ->findOrFail($messageId);

        // Only allow feedback on AI messages
        if (!$message->is_ai) {
            return response()->json(['error' => 'Feedback can only be submitted for AI messages'], 400);
        }

        // Update or create feedback
        $feedback = MessageFeedback::updateOrCreate(
            [
                'message_id' => $messageId,
                'user_id' => Auth::id(),
            ],
            [
                'feedback' => $request->feedback,
                'comment' => $request->comment,
                'issue_type' => $request->issue_type,
                'confidence_score' => $message->confidence_score,
                'detected_dialect' => $message->detected_dialect,
            ]
        );

        Log::info('AI feedback submitted', [
            'message_id' => $messageId,
            'feedback' => $request->feedback,
            'user_id' => Auth::id(),
        ]);

        return response()->json(['success' => true, 'feedback' => $feedback]);
    }

    /**
     * Get feedback statistics for training dashboard
     */
    public function statistics(Request $request)
    {
        $businessId = $request->query('business_id');
        
        $query = MessageFeedback::whereHas('message.conversation.channel', function ($q) {
                $q->where('user_id', Auth::id());
            });

        if ($businessId) {
            $query->whereHas('message.conversation', function ($q) use ($businessId) {
                $q->where('business_id', $businessId);
            });
        }

        $totalFeedback = $query->count();
        $positiveCount = (clone $query)->positive()->count();
        $negativeCount = (clone $query)->negative()->count();

        // Issue breakdown
        $issueBreakdown = (clone $query)->negative()
            ->selectRaw('issue_type, COUNT(*) as count')
            ->groupBy('issue_type')
            ->get()
            ->pluck('count', 'issue_type')
            ->toArray();

        // Dialect breakdown
        $dialectBreakdown = (clone $query)
            ->selectRaw('detected_dialect, COUNT(*) as count')
            ->groupBy('detected_dialect')
            ->get()
            ->pluck('count', 'detected_dialect')
            ->toArray();

        // Confidence score analysis
        $avgConfidence = (clone $query)->avg('confidence_score');
        $positiveAvgConfidence = (clone $query)->positive()->avg('confidence_score');
        $negativeAvgConfidence = (clone $query)->negative()->avg('confidence_score');

        return response()->json([
            'total_feedback' => $totalFeedback,
            'positive_count' => $positiveCount,
            'negative_count' => $negativeCount,
            'positive_rate' => $totalFeedback > 0 ? round(($positiveCount / $totalFeedback) * 100, 2) : 0,
            'issue_breakdown' => $issueBreakdown,
            'dialect_breakdown' => $dialectBreakdown,
            'avg_confidence' => round($avgConfidence ?? 0, 2),
            'positive_avg_confidence' => round($positiveAvgConfidence ?? 0, 2),
            'negative_avg_confidence' => round($negativeAvgConfidence ?? 0, 2),
        ]);
    }

    /**
     * Get recent feedback entries
     */
    public function recent(Request $request)
    {
        $limit = $request->query('limit', 20);
        
        $feedback = MessageFeedback::whereHas('message.conversation.channel', function ($q) {
                $q->where('user_id', Auth::id());
            })
            ->with(['message.conversation', 'user'])
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();

        return response()->json($feedback);
    }
}
