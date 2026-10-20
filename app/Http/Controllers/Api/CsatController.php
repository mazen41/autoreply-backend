<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CsatRating;
use App\Models\Conversation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\CsatSurveyInvite;
use App\Services\ActiveBusiness;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CsatController extends Controller
{
    public function createSurveyLink(Request $request, int $conversationId, ActiveBusiness $activeBusiness)
    {
        $business = $activeBusiness->resolve($request, permission: 'conversation_write');

        // Serialize invite creation per conversation so concurrent requests cannot
        // both pass the active-invite check and issue separate links.
        return DB::transaction(function () use ($business, $conversationId) {
            $conversation = Conversation::query()
                ->where('business_id', $business->id)
                ->whereKey($conversationId)
                ->lockForUpdate()
                ->firstOrFail();
            abort_unless(in_array($conversation->status, ['closed', 'resolved'], true), 422, 'Resolve the conversation before requesting feedback.');

            $contactHash = hash('sha256', $business->id . '|' . (string) $conversation->sender_id);
            abort_if(DB::table('csat_survey_suppressions')->where('business_id', $business->id)->where('contact_hash', $contactHash)->exists(), 409, 'This contact opted out of feedback requests.');
            abort_if(CsatSurveyInvite::query()->where('conversation_id', $conversation->id)->whereNotNull('responded_at')->exists(), 409, 'Feedback has already been submitted for this conversation.');

            $pending = CsatSurveyInvite::query()->where('conversation_id', $conversation->id)->whereNull('responded_at')->whereNull('opted_out_at')->where('expires_at', '>', now())->exists();
            abort_if($pending, 409, 'A feedback link is already active for this conversation.');

            $token = Str::random(64);
            $expiresAt = now()->addDays(14);
            CsatSurveyInvite::create([
                'business_id' => $business->id,
                'conversation_id' => $conversation->id,
                'token_hash' => hash('sha256', $token),
                'contact_hash' => $contactHash,
                'expires_at' => $expiresAt,
            ]);
            return response()->json(['token' => $token, 'expires_at' => $expiresAt->toIso8601String()], 201);
        });
    }

    public function publicSurvey(string $token)
    {
        $invite = CsatSurveyInvite::query()->with('business')->where('token_hash', hash('sha256', $token))->firstOrFail();
        return response()->json([
            'business_name' => $invite->business?->business_name ?: $invite->business?->name ?: 'Customer Support',
            'active' => !$invite->responded_at && !$invite->opted_out_at && $invite->expires_at->isFuture(),
        ]);
    }

    public function submitPublicSurvey(Request $request, string $token)
    {
        $data = $request->validate(['rating' => ['required', 'in:positive,negative'], 'feedback' => ['nullable', 'string', 'max:500']]);
        return DB::transaction(function () use ($data, $token) {
            $invite = CsatSurveyInvite::query()->where('token_hash', hash('sha256', $token))->lockForUpdate()->firstOrFail();
            abort_if($invite->expires_at->isPast() || $invite->responded_at || $invite->opted_out_at, 410, 'This feedback link is no longer active.');
            $rating = CsatRating::create([
                'business_id' => $invite->business_id,
                'conversation_id' => $invite->conversation_id,
                'user_id' => null,
                'rating' => $data['rating'],
                'feedback' => $data['feedback'] ?? null,
                'source' => 'customer_survey',
                'rated_at' => now(),
            ]);
            $invite->update(['responded_at' => now()]);
            return response()->json(['success' => true, 'rating' => $rating->rating]);
        });
    }

    public function optOutPublicSurvey(string $token)
    {
        $invite = CsatSurveyInvite::query()->where('token_hash', hash('sha256', $token))->firstOrFail();
        DB::table('csat_survey_suppressions')->updateOrInsert(
            ['business_id' => $invite->business_id, 'contact_hash' => $invite->contact_hash],
            ['opted_out_at' => now(), 'updated_at' => now(), 'created_at' => now()],
        );
        $invite->update(['opted_out_at' => now()]);
        return response()->json(['success' => true]);
    }

    /**
     * Submit CSAT rating
     */
    public function submitRating(Request $request, $conversationId)
    {
        $request->validate([
            'rating' => 'required|in:positive,negative',
            'feedback' => 'nullable|string|max:500',
        ]);

        $businessIds = app(\App\Services\BusinessAccessService::class)
            ->accessibleBusinessIds($request->user(), 'conversation_write');
        $conversation = Conversation::query()
            ->whereIn('business_id', $businessIds)
            ->findOrFail($conversationId);

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
        $businessIds = app(\App\Services\BusinessAccessService::class)
            ->accessibleBusinessIds($request->user(), 'conversation_read');
        Conversation::query()
            ->whereIn('business_id', $businessIds)
            ->findOrFail($conversationId);

        $rating = CsatRating::where('conversation_id', $conversationId)
            ->where('user_id', Auth::id())
            ->first();

        return response()->json($rating);
    }
}
