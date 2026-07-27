<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProactiveCampaign;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Bus;
use App\Jobs\SendProactiveMessage;

class ProactiveController extends Controller
{
    /**
     * Get all proactive campaigns
     */
    public function index(Request $request)
    {
        $user = Auth::user();
        
        $campaigns = ProactiveCampaign::where('user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'campaigns' => $campaigns,
            'total' => $campaigns->count(),
        ]);
    }

    /**
     * Create a new proactive campaign
     */
    public function store(Request $request)
    {
        $user = Auth::user();
        
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'message' => 'required|string',
            'scheduled_for' => 'required|date|after:now',
            'segment_config' => 'required|array',
            'segment_config.channels' => 'array',
            'segment_config.tags' => 'array',
            'segment_config.date_range' => 'array',
            'segment_config.message_count_min' => 'nullable|integer',
            'segment_config.message_count_max' => 'nullable|integer',
        ]);

        // Calculate target audience
        $targetAudience = $this->calculateTargetAudience($user, $validated['segment_config']);
        
        $campaign = ProactiveCampaign::create([
            'user_id' => $user->id,
            'name' => $validated['name'],
            'message' => $validated['message'],
            'scheduled_for' => $validated['scheduled_for'],
            'status' => 'scheduled',
            'segment_config' => $validated['segment_config'],
            'recipients_count' => $targetAudience->count(),
        ]);

        Log::info('Proactive campaign created', [
            'campaign_id' => $campaign->id,
            'user_id' => $user->id,
            'recipients_count' => $campaign->recipients_count
        ]);

        return response()->json([
            'message' => 'Campaign created successfully',
            'campaign' => $campaign,
            'estimated_recipients' => $campaign->recipients_count
        ], 201);
    }

    /**
     * Send campaign immediately
     */
    public function sendNow(Request $request, $id)
    {
        $user = Auth::user();
        $campaign = ProactiveCampaign::where('user_id', $user->id)->find($id);

        if (!$campaign) {
            return response()->json(['error' => 'Campaign not found'], 404);
        }

        if ($campaign->status === 'sent') {
            return response()->json(['error' => 'Campaign already sent'], 400);
        }

        // Update campaign status
        $campaign->update(['status' => 'in_progress']);

        // Get target audience
        $targetAudience = $this->calculateTargetAudience($user, $campaign->segment_config);

        // Dispatch jobs for each recipient
        $jobs = [];
        foreach ($targetAudience as $conversation) {
            $jobs[] = new SendProactiveMessage($campaign->id, $conversation->id);
        }

        // Dispatch as batch
        Bus::batch($jobs)->dispatch();

        // Update campaign status
        $campaign->update([
            'status' => 'sent',
            'sent_at' => now(),
            'delivered_count' => $targetAudience->count()
        ]);

        Log::info('Proactive campaign sent', [
            'campaign_id' => $campaign->id,
            'user_id' => $user->id,
            'recipients_count' => $targetAudience->count()
        ]);

        return response()->json([
            'message' => 'Campaign sent successfully',
            'campaign' => $campaign->fresh()
        ]);
    }

    /**
     * Cancel a scheduled campaign
     */
    public function cancel(Request $request, $id)
    {
        $user = Auth::user();
        $campaign = ProactiveCampaign::where('user_id', $user->id)->find($id);

        if (!$campaign) {
            return response()->json(['error' => 'Campaign not found'], 404);
        }

        if ($campaign->status !== 'scheduled') {
            return response()->json(['error' => 'Can only cancel scheduled campaigns'], 400);
        }

        $campaign->update(['status' => 'cancelled']);

        return response()->json([
            'message' => 'Campaign cancelled',
            'campaign' => $campaign->fresh()
        ]);
    }

    /**
     * Delete a campaign
     */
    public function destroy(Request $request, $id)
    {
        $user = Auth::user();
        $campaign = ProactiveCampaign::where('user_id', $user->id)->find($id);

        if (!$campaign) {
            return response()->json(['error' => 'Campaign not found'], 404);
        }

        if ($campaign->status === 'sent') {
            return response()->json(['error' => 'Cannot delete sent campaigns'], 400);
        }

        $campaign->delete();

        return response()->json(['message' => 'Campaign deleted']);
    }

    /**
     * Calculate target audience based on segment configuration
     */
    private function calculateTargetAudience($user, $segmentConfig)
    {
        $query = Conversation::whereHas('channel', function ($query) use ($user) {
            $query->where('user_id', $user->id);
        });

        // Filter by channels
        if (!empty($segmentConfig['channels'])) {
            $query->whereHas('channel', function ($q) use ($segmentConfig) {
                $q->whereIn('type', $segmentConfig['channels']);
            });
        }

        // Filter by tags
        if (!empty($segmentConfig['tags'])) {
            $query->whereHas('tags', function ($q) use ($segmentConfig) {
                $q->whereIn('tag', $segmentConfig['tags']);
            });
        }

        // Filter by date range
        if (!empty($segmentConfig['date_range'])) {
            $startDate = $segmentConfig['date_range']['start'] ?? null;
            $endDate = $segmentConfig['date_range']['end'] ?? null;

            if ($startDate) {
                $query->where('created_at', '>=', $startDate);
            }
            if ($endDate) {
                $query->where('created_at', '<=', $endDate);
            }
        }

        // Filter by message count
        if (isset($segmentConfig['message_count_min'])) {
            $query->whereHas('messages', function ($q) use ($segmentConfig) {
                $q->where('direction', 'inbound');
            }, '>=', $segmentConfig['message_count_min']);
        }

        if (isset($segmentConfig['message_count_max'])) {
            $query->whereHas('messages', function ($q) use ($segmentConfig) {
                $q->where('direction', 'inbound');
            }, '<=', $segmentConfig['message_count_max']);
        }

        return $query->get();
    }

    /**
     * Get campaign statistics
     */
    public function getStats(Request $request, $id)
    {
        $user = Auth::user();
        $campaign = ProactiveCampaign::where('user_id', $user->id)->find($id);

        if (!$campaign) {
            return response()->json(['error' => 'Campaign not found'], 404);
        }

        // Get messages sent from this campaign
        $messages = Message::where('source', 'proactive_campaign_' . $campaign->id)->get();

        $stats = [
            'total_sent' => $campaign->delivered_count,
            'successful' => $messages->where('send_status', 'sent')->count(),
            'failed' => $messages->where('send_status', 'failed')->count(),
            'responses' => $messages->whereHas('conversation.messages', function ($q) {
                $q->where('direction', 'inbound')->where('created_at', '>', $q->created_at);
            })->count(),
        ];

        return response()->json(['stats' => $stats]);
    }
}