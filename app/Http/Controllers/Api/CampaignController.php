<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\CampaignLog;
use App\Models\Conversation;
use App\Models\Channel;
use App\Models\BusinessProfile;
use App\Jobs\SendCampaignMessage;
use Carbon\Carbon;
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
        $this->authorizeBusiness($businessId);

        $campaigns = Campaign::where('business_id', $businessId)
            ->with(['channel'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')))
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($campaigns);
    }

    /**
     * Create a new campaign
     */
    public function store(Request $request, $businessId)
    {
        $this->authorizeBusiness($businessId);

        $request->validate([
            'channel_id' => 'required|exists:channels,id',
            'name' => 'required|string|max:255',
            'message' => 'required|string',
            'scheduled_at' => 'nullable|date',
            'timezone' => 'nullable|string',
            'filters' => 'nullable|array',
            'filters.tags' => 'nullable|array',
            'filters.tags.*' => 'string|max:100',
            'filters.last_activity_days' => 'nullable|integer|min:1|max:365',
        ]);

        $this->authorizeChannel($businessId, (int) $request->channel_id);

        $scheduledAt = $this->parseScheduledAt($businessId, $request->scheduled_at, $request->timezone);
        if ($request->filled('scheduled_at') && $scheduledAt === false) {
            return response()->json(['error' => 'Invalid scheduled_at value.'], 422);
        }
        if ($scheduledAt && $scheduledAt->lte(Carbon::now('UTC'))) {
            return response()->json(['error' => 'Scheduled time must be in the future.'], 422);
        }

        $campaign = Campaign::create([
            'business_id' => $businessId,
            'channel_id' => $request->channel_id,
            'name' => $request->name,
            'message' => $request->message,
            'status' => $scheduledAt ? 'scheduled' : 'draft',
            'scheduled_at' => $scheduledAt,
            'filters' => $request->filters,
        ]);

        return response()->json(['success' => true, 'campaign' => $campaign]);
    }

    /**
     * Interpret the frontend's naive "datetime-local" scheduled_at value in
     * the caller's real IANA timezone (auto-detected client-side and sent
     * with the request, mirroring EmailCampaignController), then store as
     * UTC. Without this, a campaign "scheduled" for 10:00 in Cairo was
     * previously stored as if it meant 10:00 UTC — firing 2-3 hours early.
     *
     * Returns null when no scheduled_at was given, false on a parse error,
     * or a UTC Carbon instance otherwise.
     */
    private function parseScheduledAt($businessId, ?string $scheduledAt, ?string $timezone)
    {
        if (!$scheduledAt) {
            return null;
        }

        $tz = 'UTC';
        if ($timezone && in_array($timezone, timezone_identifiers_list(), true)) {
            $tz = $timezone;
            $business = BusinessProfile::find($businessId);
            if ($business && $business->timezone !== $tz) {
                $business->update(['timezone' => $tz]);
            }
        } else {
            $business = BusinessProfile::find($businessId);
            $tz = $business?->timezone ?: 'UTC';
        }

        try {
            return Carbon::parse($scheduledAt, $tz)->utc();
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Update a campaign
     */
    public function update(Request $request, $businessId, $campaignId)
    {
        $this->authorizeBusiness($businessId);

        $request->validate([
            'channel_id' => 'sometimes|required|exists:channels,id',
            'name' => 'sometimes|string|max:255',
            'message' => 'sometimes|string',
            'scheduled_at' => 'nullable|date',
            'timezone' => 'nullable|string',
            'filters' => 'nullable|array',
            'filters.tags' => 'nullable|array',
            'filters.tags.*' => 'string|max:100',
            'filters.last_activity_days' => 'nullable|integer|min:1|max:365',
        ]);

        $campaign = Campaign::where('business_id', $businessId)
            ->findOrFail($campaignId);

        if ($campaign->status === 'sent' || $campaign->status === 'sending') {
            return response()->json(['error' => 'Cannot update sent or sending campaigns'], 400);
        }

        if ($request->filled('channel_id')) {
            $this->authorizeChannel($businessId, (int) $request->channel_id);
        }

        $updates = $request->only(['channel_id', 'name', 'message', 'filters']);
        if ($request->has('scheduled_at')) {
            $scheduledAt = $this->parseScheduledAt($businessId, $request->scheduled_at, $request->timezone);
            if ($request->filled('scheduled_at') && $scheduledAt === false) {
                return response()->json(['error' => 'Invalid scheduled_at value.'], 422);
            }
            if ($scheduledAt && $scheduledAt->lte(Carbon::now('UTC'))) {
                return response()->json(['error' => 'Scheduled time must be in the future.'], 422);
            }
            $updates['scheduled_at'] = $scheduledAt;
            $updates['status'] = $scheduledAt ? 'scheduled' : 'draft';
        }

        $campaign->update($updates);

        return response()->json(['success' => true, 'campaign' => $campaign]);
    }

    /**
     * Delete a campaign
     */
    public function destroy(Request $request, $businessId, $campaignId)
    {
        $this->authorizeBusiness($businessId);

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
        $this->authorizeBusiness($businessId);

        return $this->launchCampaign($businessId, $campaignId, ['draft']);
    }

    public function launchScheduled(Request $request, $businessId, $campaignId)
    {
        return $this->launchCampaign($businessId, $campaignId, ['scheduled']);
    }

    private function launchCampaign($businessId, $campaignId, array $allowedStatuses)
    {
        $campaign = Campaign::where('business_id', $businessId)
            ->findOrFail($campaignId);

        if (!in_array($campaign->status, $allowedStatuses, true)) {
            return response()->json(['error' => 'Campaign is not in a launchable status'], 400);
        }

        // Atomically claim the campaign by flipping its status here, before
        // building the audience or dispatching any jobs. Without this, two
        // concurrent launch calls for the same campaign (e.g. the
        // `campaigns:send-due` cron firing at the same moment as a manual
        // "Launch Now" click, or two overlapping cron runs) could both pass
        // the check above and both build the audience + dispatch a full set
        // of SendCampaignMessage jobs — sending every recipient the campaign
        // twice. The affected-rows count tells us whether we actually won
        // the race; if another request already flipped the status, we lost
        // and must not proceed.
        $claimed = Campaign::where('id', $campaignId)
            ->where('business_id', $businessId)
            ->whereIn('status', $allowedStatuses)
            ->update(['status' => 'sending', 'sent_at' => now()]);

        if ($claimed === 0) {
            return response()->json(['error' => 'Campaign is not in a launchable status'], 400);
        }

        $campaign->refresh();

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

        // An empty audience means there is nothing left to make the campaign
        // progress — no SendCampaignMessage job will ever run to flip its
        // status, so without this it would stay stuck on "sending" forever.
        if ($conversations->isEmpty()) {
            $campaign->update([
                'total_recipients' => 0,
                'status' => 'failed',
                'error_message' => 'No matching recipients found for this campaign\'s audience filters.',
            ]);

            return response()->json(['error' => 'No matching recipients found for this campaign\'s audience filters.'], 400);
        }

        $campaign->update(['total_recipients' => $conversations->count()]);

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

        return response()->json(['success' => true, 'campaign' => $campaign->fresh()]);
    }

    public function cancelSchedule(Request $request, $businessId, $campaignId)
    {
        $this->authorizeBusiness($businessId);

        $campaign = Campaign::where('business_id', $businessId)
            ->findOrFail($campaignId);

        if ($campaign->status !== 'scheduled') {
            return response()->json(['error' => 'Only scheduled campaigns can be cancelled'], 400);
        }

        $campaign->update([
            'status' => 'draft',
            'scheduled_at' => null,
        ]);

        return response()->json(['success' => true, 'campaign' => $campaign]);
    }

    /**
     * Get campaign logs
     */
    public function logs(Request $request, $businessId, $campaignId)
    {
        $this->authorizeBusiness($businessId);

        $campaign = Campaign::where('business_id', $businessId)
            ->findOrFail($campaignId);

        $logs = $campaign->logs()
            ->with(['conversation'])
            ->orderBy('created_at', 'desc')
            ->paginate(50);

        return response()->json($logs);
    }

    private function authorizeBusiness($businessId): void
    {
        $ownsBusiness = \App\Models\BusinessProfile::where('id', $businessId)
            ->where('user_id', Auth::id())
            ->exists();

        if (!$ownsBusiness) {
            abort(404);
        }
    }

    private function authorizeChannel(int $businessId, int $channelId): void
    {
        $ownsChannel = Channel::where('id', $channelId)
            ->where('business_id', $businessId)
            ->where('user_id', Auth::id())
            ->whereIn('type', ['whatsapp', 'instagram', 'facebook', 'telegram', 'gmail'])
            ->exists();

        if (!$ownsChannel) {
            abort(422, 'Select a valid connected messaging channel for this business.');
        }
    }
}
