<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CsatRating;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CsatController extends Controller
{
    /**
     * Submit CSAT rating
     */
    public function submitRating(Request $request, $conversationId)
    {
        $request->validate([
            'rating' => 'required|in:positive,negative',
            'feedback' => 'nullable|string|max:500',
        ]);

        $conversation = \App\Models\Conversation::find($conversationId);
        
        if (!$conversation) {
            return response()->json(['error' => 'Conversation not found'], 404);
        }

        // Check if already rated
        $existingRating = CsatRating::where('conversation_id', $conversationId)
            ->where('user_id', Auth::id())
            ->first();

        if ($existingRating) {
            return response()->json(['error' => 'Already rated this conversation'], 400);
        }

        $rating = CsatRating::create([
            'business_id' => $conversation->business_id,
            'conversation_id' => $conversationId,
            'user_id' => Auth::id(),
            'rating' => $request->rating,
            'feedback' => $request->feedback,
            'rated_at' => now(),
        ]);

        return response()->json(['success' => true, 'rating' => $rating]);
    }

    /**
     * Get CSAT rating for a conversation
     */
    public function getRating(Request $request, $conversationId)
    {
        $rating = CsatRating::where('conversation_id', $conversationId)
            ->where('user_id', Auth::id())
            ->first();

        return response()->json($rating);
    }
}
