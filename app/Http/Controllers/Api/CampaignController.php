<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\Channel;
use App\Models\BusinessProfile;
use App\Services\CampaignLaunchService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CampaignController extends Controller
{
    /**
     * Get all campaigns for a business
     */
    public function index(Request $request, $businessId)
    {
        $campaigns = Campaign::where('business_id', $businessId)
            ->with(['channel'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')))
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($campaigns);
    }

    /** Preview a messaging campaign's eligible audience on its chosen sender. */
    public function audiencePreview(Request $request, $businessId)
    {
        $validated = $request->validate([
            'channel_id' => 'required|integer',
            'whatsapp_cloud_template_id' => 'nullable|integer',
            'filters' => 'nullable|array',
            'filters.tags' => 'nullable|array',
            'filters.tags.*' => 'string|max:100',
            'filters.last_activity_days' => 'nullable|integer|min:1|max:365',
        ]);

        $this->authorizeChannel((int) $businessId, (int) $validated['channel_id']);
        $channel = Channel::where('business_id', $businessId)->findOrFail($validated['channel_id']);

        return response()->json(app(\App\Services\CampaignAudiencePreviewService::class)->preview(
            $channel,
            $validated['filters'] ?? [],
            $validated['whatsapp_cloud_template_id'] ?? null
        ));
    }

    /**
     * Create a new campaign
     */
    public function store(Request $request, $businessId)
    {
        $request->validate([
            'channel_id' => 'required|exists:channels,id',
            'whatsapp_cloud_template_id' => 'nullable|integer|exists:whatsapp_cloud_templates,id',
            'whatsapp_template_components' => 'nullable|array',
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
        $channel = Channel::where('business_id', $businessId)->findOrFail($request->channel_id);
        $whatsappValidationError = $this->whatsappCampaignValidationError($channel, $businessId, $request->whatsapp_cloud_template_id);
        if ($whatsappValidationError) {
            return response()->json(['error' => $whatsappValidationError], 422);
        }

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
            'whatsapp_cloud_template_id' => $request->whatsapp_cloud_template_id,
            'name' => $request->name,
            'message' => $request->message,
            'whatsapp_template_components' => $request->whatsapp_template_components,
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
        $request->validate([
            'channel_id' => 'sometimes|required|exists:channels,id',
            'whatsapp_cloud_template_id' => 'nullable|integer|exists:whatsapp_cloud_templates,id',
            'whatsapp_template_components' => 'nullable|array',
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

        $channel = $request->filled('channel_id')
            ? Channel::where('business_id', $businessId)->findOrFail($request->channel_id)
            : $campaign->channel;
        $templateId = $request->has('whatsapp_cloud_template_id')
            ? $request->whatsapp_cloud_template_id
            : $campaign->whatsapp_cloud_template_id;
        $whatsappValidationError = $this->whatsappCampaignValidationError($channel, $businessId, $templateId);
        if ($whatsappValidationError) {
            return response()->json(['error' => $whatsappValidationError], 422);
        }

        $updates = $request->only([
            'channel_id', 'name', 'message', 'filters',
            'whatsapp_cloud_template_id', 'whatsapp_template_components',
        ]);
        if ($request->filled('channel_id') && $channel->type !== 'whatsapp'
            && !$request->has('whatsapp_cloud_template_id')) {
            $updates['whatsapp_cloud_template_id'] = null;
            $updates['whatsapp_template_components'] = null;
        }
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
        $result = app(CampaignLaunchService::class)->launch($businessId, $campaignId, ['draft']);
        $status = $result['status'] ?? 200;
        unset($result['status']);

        return response()->json($result, $status);
    }

    public function cancelSchedule(Request $request, $businessId, $campaignId)
    {
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

    public function pause(Request $request, $businessId, $campaignId)
    {
        $business = app(\App\Services\ActiveBusiness::class)->resolve(
            $request, permission: 'automation_write', routeBusinessId: $businessId
        );
        $campaign = Campaign::where('business_id', $business->id)->findOrFail($campaignId);
        app(\App\Services\AutomationRunControl::class)->pauseCampaign($business, $campaign, true);

        return response()->json(['success' => true, 'paused' => true, 'campaign' => $campaign->fresh()]);
    }

    public function resume(Request $request, $businessId, $campaignId)
    {
        $business = app(\App\Services\ActiveBusiness::class)->resolve(
            $request, permission: 'automation_write', routeBusinessId: $businessId
        );
        $campaign = Campaign::where('business_id', $business->id)->findOrFail($campaignId);
        app(\App\Services\AutomationRunControl::class)->pauseCampaign($business, $campaign, false);

        return response()->json(['success' => true, 'paused' => false, 'campaign' => $campaign->fresh()]);
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

    private function authorizeChannel(int $businessId, int $channelId): void
    {
        $ownsChannel = Channel::where('id', $channelId)
            ->where('business_id', $businessId)
            ->whereIn('type', ['whatsapp', 'instagram', 'facebook', 'telegram', 'gmail', 'salla'])
            ->exists();

        if (!$ownsChannel) {
            abort(422, 'Select a valid connected messaging channel for this business.');
        }
    }

    private function whatsappCampaignValidationError(?Channel $channel, int|string $businessId, mixed $templateId): ?string
    {
        if (!$channel || $channel->type !== 'whatsapp') {
            return null;
        }

        if (($channel->metadata['whatsapp_provider'] ?? null) !== 'cloud') {
            return null;
        }

        $templateIsApprovedForChannel = $templateId && \App\Models\WhatsAppCloudTemplate::query()
            ->where('id', $templateId)
            ->where('business_id', $businessId)
            ->where('channel_id', $channel->id)
            ->where('status', 'APPROVED')
            ->exists();

        return $templateIsApprovedForChannel
            ? null
            : 'Select an approved WhatsApp Cloud API template for this channel.';
    }
}
