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
     * Get feedback statistics for training dashboard.
     *
     * Scoped to the authenticated user's channels (and optionally narrowed to a
     * business the user owns). Confidence averages exclude NULL and out-of-range
     * values so a bad row cannot corrupt the reported average.
     */
    public function statistics(Request $request)
    {
        $businessId = $request->query('business_id');

        if ($businessId) {
            \App\Models\BusinessProfile::where('user_id', Auth::id())
                ->where('id', $businessId)
                ->firstOrFail();
        }

        $scope = function ($q) use ($businessId) {
            $q->whereHas('message.conversation.channel', function ($q2) use ($businessId) {
                $q2->where('user_id', Auth::id());
                if ($businessId) {
                    $q2->where('business_id', $businessId);
                }
            });
        };

        $query = MessageFeedback::query();
        $scope($query);

        $totalFeedback = (clone $query)->count();
        $positiveCount = (clone $query)->positive()->count();
        $negativeCount = (clone $query)->negative()->count();

        // Issue breakdown
        $issueBreakdown = (clone $query)->negative()
            ->whereNotNull('issue_type')
            ->selectRaw('issue_type, COUNT(*) as count')
            ->groupBy('issue_type')
            ->pluck('count', 'issue_type')
            ->toArray();

        // Dialect breakdown (real values only — never NULL)
        $dialectBreakdown = (clone $query)
            ->whereNotNull('detected_dialect')
            ->selectRaw('detected_dialect, COUNT(*) as count')
            ->groupBy('detected_dialect')
            ->pluck('count', 'detected_dialect')
            ->toArray();

        // Confidence: NULL is excluded (AVG ignores it) and out-of-range scores
        // are normalized so they cannot skew the result. Values are 0–1 here.
        $confSelect = 'AVG(CASE WHEN confidence_score BETWEEN 0 AND 1 THEN confidence_score
                                WHEN confidence_score > 1 AND confidence_score <= 100 THEN confidence_score / 100 END)';
        $avgConfidence = (clone $query)->selectRaw($confSelect)->value($confSelect);
        $positiveAvg = (clone $query)->positive()->selectRaw($confSelect)->value($confSelect);
        $negativeAvg = (clone $query)->negative()->selectRaw($confSelect)->value($confSelect);

        return response()->json([
            'total_feedback' => $totalFeedback,
            'positive_count' => $positiveCount,
            'negative_count' => $negativeCount,
            'positive_rate' => $totalFeedback > 0 ? round(($positiveCount / $totalFeedback) * 100, 2) : 0,
            'issue_breakdown' => $issueBreakdown,
            'dialect_breakdown' => $dialectBreakdown,
            'avg_confidence' => $avgConfidence !== null ? round((float) $avgConfidence, 4) : null,
            'positive_avg_confidence' => $positiveAvg !== null ? round((float) $positiveAvg, 4) : null,
            'negative_avg_confidence' => $negativeAvg !== null ? round((float) $negativeAvg, 4) : null,
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
