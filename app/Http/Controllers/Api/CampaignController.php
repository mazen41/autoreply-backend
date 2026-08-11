<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\CampaignLog;
use App\Models\Conversation;
use App\Models\Channel;
use App\Jobs\SendCampaignMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class CampaignController extends Controller
{
    /**
     * Get all campaigns for a business
     */
    public function index(Request $request, $businessId)
    {
        $campaigns = Campaign::where('business_id', $businessId)
            ->with(['channel'])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($campaigns);
    }

    /**
     * Create a new campaign
     */
    public function store(Request $request, $businessId)
    {
        $request->validate([
            'channel_id' => 'required|exists:channels,id',
            'name' => 'required|string|max:255',
            'message' => 'required|string',
            'scheduled_at' => 'nullable|date',
            'filters' => 'nullable|array',
        ]);

        $campaign = Campaign::create([
            'business_id' => $businessId,
            'channel_id' => $request->channel_id,
            'name' => $request->name,
            'message' => $request->message,
            'status' => $request->scheduled_at ? 'scheduled' : 'draft',
            'scheduled_at' => $request->scheduled_at,
            'filters' => $request->filters,
        ]);

        return response()->json(['success' => true, 'campaign' => $campaign]);
    }

    /**
     * Update a campaign
     */
    public function update(Request $request, $businessId, $campaignId)
    {
        $request->validate([
            'name' => 'sometimes|string|max:255',
            'message' => 'sometimes|string',
            'scheduled_at' => 'nullable|date',
            'filters' => 'nullable|array',
        ]);

        $campaign = Campaign::where('business_id', $businessId)
            ->findOrFail($campaignId);

        if ($campaign->status === 'sent' || $campaign->status === 'sending') {
            return response()->json(['error' => 'Cannot update sent or sending campaigns'], 400);
        }

        $campaign->update($request->only(['name', 'message', 'scheduled_at', 'filters']));

        return response()->json(['success' => true, 'campaign' => $campaign]);
    }

    /**
     * Delete a campaign
     */
    public function destroy(Request $request, $businessId, $campaignId)
    {
        $campaign = Campaign::where('business_id', $businessId)
            ->findOrFail($campaignId);

        if ($campaign->status === 'sending') {
            return response()->json(['error' => 'Cannot delete campaign currently being sent'], 400);
        }

        $campaign->logs()->delete();
        $campaign->delete();

        return response()->json(['success' => true]);
    }

    /**
     * Launch a campaign
     */
    public function launch(Request $request, $businessId, $campaignId)
    {
        $campaign = Campaign::where('business_id', $businessId)
            ->findOrFail($campaignId);

        if ($campaign->status !== 'draft') {
            return response()->json(['error' => 'Only draft campaigns can be launched'], 400);
        }

        // Build audience based on filters
        $query = Conversation::whereHas('channel', function ($q) use ($campaign) {
                $q->where('id', $campaign->channel_id);
            });

        // Apply filters if provided
        if ($campaign->filters) {
            if (isset($campaign->filters['tags'])) {
                $query->whereHas('tags', function ($q) use ($campaign) {
                    $q->whereIn('tag', $campaign->filters['tags']);
                });
            }
            if (isset($campaign->filters['last_activity_days'])) {
                $query->where('last_message_at', '>=', now()->subDays($campaign->filters['last_activity_days']));
            }
        }

        $conversations = $query->get();
        $campaign->update([
            'status' => 'sending',
            'total_recipients' => $conversations->count(),
            'sent_at' => now(),
        ]);

        // Create campaign logs and queue jobs
        foreach ($conversations as $conversation) {
            $campaignLog = CampaignLog::create([
                'campaign_id' => $campaign->id,
                'conversation_id' => $conversation->id,
                'status' => 'queued',
            ]);

            SendCampaignMessage::dispatch($campaignLog->id);
        }

        Log::info('Campaign launched', [
            'campaign_id' => $campaign->id,
            'recipients' => $conversations->count(),
        ]);

        return response()->json(['success' => true, 'campaign' => $campaign]);
    }

    /**
     * Get campaign logs
     */
    public function logs(Request $request, $businessId, $campaignId)
    {
        $campaign = Campaign::where('business_id', $businessId)
            ->findOrFail($campaignId);

        $logs = $campaign->logs()
            ->with(['conversation'])
            ->orderBy('created_at', 'desc')
            ->paginate(50);

        return response()->json($logs);
    }
}
