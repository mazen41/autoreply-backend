<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SendEmailCampaignMessage;
use App\Models\BusinessProfile;
use App\Models\EmailCampaign;
use App\Models\EmailCampaignRecipient;
use App\Services\EmailCampaignAudienceService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class EmailCampaignController extends Controller
{
    public function __construct(private EmailCampaignAudienceService $audience) {}

    /**
     * Resolve the authenticated user's business, enforcing ownership.
     */
    private function business(): BusinessProfile
    {
        return BusinessProfile::where('user_id', Auth::id())->firstOrFail();
    }

    /**
     * Resolve which timezone to interpret a naive "datetime-local" value in.
     *
     * The frontend detects the browser's real IANA timezone automatically
     * (Intl.DateTimeFormat().resolvedOptions().timeZone) and sends it with
     * every write — this is exact, works for any country (Egypt, Saudi
     * Arabia, wherever the user actually is), and needs no manual setup.
     *
     * If it's present and valid, we use it directly and also persist it onto
     * the business profile so it stays in sync for other features (business
     * hours, etc.) and so the schedule/index responses reflect it too.
     *
     * Falls back to whatever is already stored on the business, then UTC,
     * only if the frontend somehow didn't send one.
     */
    private function resolveTimezone(BusinessProfile $business, ?string $requestTimezone): string
    {
        if ($requestTimezone && in_array($requestTimezone, timezone_identifiers_list(), true)) {
            if ($business->timezone !== $requestTimezone) {
                $business->update(['timezone' => $requestTimezone]);
            }

            return $requestTimezone;
        }

        return $business->timezone ?: 'UTC';
    }

    public function index(Request $request)
    {
        $business = $this->business();

        $query = EmailCampaign::where('business_id', $business->id)
            ->withCount('recipients')
            ->orderBy('created_at', 'desc');

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        return response()->json([
            'data' => $query->get(),
            // So the frontend can display/edit scheduled_at in the business's
            // own timezone instead of the browser's local timezone.
            'business_timezone' => $business->timezone ?: 'UTC',
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'             => 'required|string|max:255',
            'subject'          => 'required|string|max:255',
            'content'          => 'required|string',
            'audience_criteria'=> 'nullable|array',
            'audience_criteria.mode' => 'nullable|string|in:manual,gmail',
            'audience_criteria.recipients' => 'nullable|array',
            'audience_criteria.recipients.*' => 'email|max:255',
            'scheduled_at'     => 'nullable|date',
            'timezone'         => 'nullable|string',
        ]);

        $business = $this->business();
        $audienceCriteria = $this->normalizeAudienceCriteria($validated['audience_criteria'] ?? []);

        $scheduledAt = null;
        if (!empty($validated['scheduled_at'])) {
            $timezone = $this->resolveTimezone($business, $validated['timezone'] ?? null);

            try {
                $scheduledAt = Carbon::parse($validated['scheduled_at'], $timezone)->utc();
            } catch (\Exception $e) {
                return response()->json(['error' => 'Invalid scheduled_at value.'], 422);
            }
        }

        $campaign = EmailCampaign::create([
            'business_id'       => $business->id,
            'name'              => $validated['name'],
            'subject'           => $validated['subject'],
            'content'           => $validated['content'],
            'audience_criteria' => $audienceCriteria,
            'status'            => 'draft',
            'scheduled_at'      => $scheduledAt,
        ]);

        return response()->json(['success' => true, 'campaign' => $campaign], 201);
    }

    /**
     * Update a draft campaign (name, subject, content, audience_criteria, scheduled_at).
     * Only draft campaigns may be edited.
     */
    public function update(Request $request, $campaignId)
    {
        $validated = $request->validate([
            'name'             => 'sometimes|required|string|max:255',
            'subject'          => 'sometimes|required|string|max:255',
            'content'          => 'sometimes|required|string',
            'audience_criteria'=> 'nullable|array',
            'audience_criteria.mode' => 'nullable|string|in:manual,gmail',
            'audience_criteria.recipients' => 'nullable|array',
            'audience_criteria.recipients.*' => 'email|max:255',
            'scheduled_at'     => 'nullable|date',
            'timezone'         => 'nullable|string',
        ]);

        $business = $this->business();
        $campaign = EmailCampaign::where('business_id', $business->id)->findOrFail($campaignId);

        if (!in_array($campaign->status, ['draft', 'scheduled'])) {
            return response()->json(['error' => 'Only draft or scheduled campaigns can be edited'], 400);
        }

        if (array_key_exists('audience_criteria', $validated)) {
            $validated['audience_criteria'] = $this->normalizeAudienceCriteria($validated['audience_criteria'] ?? []);
        }

        // Interpret the naive datetime-local value in the timezone the
        // frontend detected from the browser, then store as UTC.
        if (array_key_exists('scheduled_at', $validated) && $validated['scheduled_at']) {
            $timezone = $this->resolveTimezone($business, $validated['timezone'] ?? null);

            try {
                $validated['scheduled_at'] = Carbon::parse($validated['scheduled_at'], $timezone)->utc();
            } catch (\Exception $e) {
                return response()->json(['error' => 'Invalid scheduled_at value.'], 422);
            }
        }

        unset($validated['timezone']);

        DB::transaction(function () use ($campaign, $validated) {
            $campaign->update($validated);
            $campaign->recipients()->delete();
            $campaign->update([
                'total_recipients' => 0,
                'delivered_count' => 0,
                'opened_count' => 0,
                'clicked_count' => 0,
                'failed_count' => 0,
                'error_message' => null,
            ]);
        });

        return response()->json(['success' => true, 'campaign' => $campaign->fresh()]);
    }

    public function send(Request $request, $campaignId)
    {
        $business = $this->business();
        $campaign = EmailCampaign::where('business_id', $business->id)->findOrFail($campaignId);

        if (!in_array($campaign->status, ['draft', 'scheduled'])) {
            return response()->json(['error' => 'Only draft or scheduled campaigns can be sent'], 400);
        }

        $this->audience->buildRecipients($campaign, true);

        if ($campaign->total_recipients === 0) {
            return response()->json(['error' => 'No valid recipients found for this campaign audience.'], 400);
        }

        $campaign->update(['status' => 'sending', 'sent_at' => now()]);

        foreach ($campaign->recipients()->where('status', 'pending')->pluck('id') as $recipientId) {
            SendEmailCampaignMessage::dispatch($recipientId);
        }

        Log::info('Email campaign send triggered', [
            'campaign_id' => $campaign->id,
            'recipients'  => $campaign->total_recipients,
        ]);

        return response()->json(['success' => true, 'campaign' => $campaign->fresh()]);
    }

    public function schedule(Request $request, $campaignId)
    {
        $validated = $request->validate([
            'scheduled_at' => 'required|date',
            'timezone'     => 'nullable|string',
        ]);

        $business = $this->business();
        $campaign = EmailCampaign::where('business_id', $business->id)->findOrFail($campaignId);

        // Allow scheduling a draft, and re-scheduling (changing the time on)
        // a campaign that is already scheduled.
        if (!in_array($campaign->status, ['draft', 'scheduled'])) {
            return response()->json(['error' => 'Only draft or scheduled campaigns can be scheduled'], 400);
        }

        // The frontend sends a naive "datetime-local" value (e.g.
        // "2026-08-20T10:00") along with the browser's real IANA timezone,
        // auto-detected client-side. Interpret it in that timezone, then
        // store as UTC so the every-minute cron comparison against now()
        // (UTC) fires at the time the user actually meant, wherever they are.
        $timezone = $this->resolveTimezone($business, $validated['timezone'] ?? null);

        try {
            $scheduledAt = Carbon::parse($validated['scheduled_at'], $timezone)->utc();
        } catch (\Exception $e) {
            return response()->json(['error' => 'Invalid scheduled_at value.'], 422);
        }

        if ($scheduledAt->lte(Carbon::now('UTC'))) {
            return response()->json(['error' => 'Scheduled time must be in the future.'], 422);
        }

        $campaign->update([
            'status'       => 'scheduled',
            'scheduled_at' => $scheduledAt,
        ]);

        $this->audience->buildRecipients($campaign, true);

        if ($campaign->total_recipients === 0) {
            $campaign->update([
                'status' => 'draft',
                'scheduled_at' => null,
                'error_message' => 'No valid recipients found for this campaign audience.',
            ]);

            return response()->json(['error' => 'No valid recipients found for this campaign audience.'], 400);
        }

        return response()->json(['success' => true, 'campaign' => $campaign->fresh()]);
    }

    /**
     * Cancel a scheduled campaign — revert it back to draft.
     */
    public function cancelSchedule($campaignId)
    {
        $business = $this->business();
        $campaign = EmailCampaign::where('business_id', $business->id)->findOrFail($campaignId);

        if ($campaign->status !== 'scheduled') {
            return response()->json(['error' => 'Only scheduled campaigns can be cancelled'], 400);
        }

        $campaign->update([
            'status'       => 'draft',
            'scheduled_at' => null,
        ]);

        return response()->json(['success' => true, 'campaign' => $campaign->fresh()]);
    }

    public function destroy($campaignId)
    {
        $business = $this->business();
        $campaign = EmailCampaign::where('business_id', $business->id)->findOrFail($campaignId);

        if ($campaign->status === 'sending') {
            return response()->json(['error' => 'Cannot delete a campaign currently being sent'], 400);
        }

        $campaign->recipients()->delete();
        $campaign->delete();

        return response()->json(['success' => true]);
    }

    /**
     * Campaign statistics (recipients breakdown).
     */
    public function stats($campaignId)
    {
        $business = $this->business();
        $campaign = EmailCampaign::where('business_id', $business->id)->findOrFail($campaignId);

        $recipientBreakdown = $campaign->recipients()
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        return response()->json([
            'campaign'   => $campaign,
            'recipients' => $recipientBreakdown,
        ]);
    }

    /**
     * Public open-tracking pixel endpoint. No auth — the recipient's mail
     * client hits this directly.
     */
    public function trackOpen($recipientId)
    {
        $recipient = $this->findRecipientForTracking($recipientId);

        if ($recipient && $recipient->opened_at === null) {
            $recipient->update(['status' => 'opened', 'opened_at' => now()]);
            $recipient->campaign()->increment('opened_count');
        }

        // 1x1 transparent GIF
        $pixel = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBTAA7');

        return response($pixel, 200)->header('Content-Type', 'image/gif');
    }

    /**
     * Public click-tracking redirect. No auth — mail client GETs this URL.
     * Increments clicked_count on first click then redirects to the real URL.
     */
    public function trackClick(Request $request, $recipientId)
    {
        $recipient = $this->findRecipientForTracking($recipientId);

        if ($recipient && $recipient->clicked_at === null) {
            $recipient->update(['clicked_at' => now()]);
            $campaign = $recipient->campaign;
            if ($campaign) {
                $campaign->increment('clicked_count');
            }
        }

        $url = $request->query('url', '/');

        // Only allow http/https destinations
        if (!filter_var($url, FILTER_VALIDATE_URL) || !in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'])) {
            $url = '/';
        }

        return redirect($url);
    }

    private function findRecipientForTracking(string $recipientKey): ?EmailCampaignRecipient
    {
        return EmailCampaignRecipient::where('tracking_token', $recipientKey)->first()
            ?: (ctype_digit($recipientKey) ? EmailCampaignRecipient::find((int) $recipientKey) : null);
    }

    private function normalizeAudienceCriteria(array $criteria): array
    {
        $mode = $criteria['mode'] ?? 'manual';
        $recipients = $criteria['recipients'] ?? [];

        if ($mode === 'gmail') {
            return ['mode' => 'gmail', 'recipients' => []];
        }

        $recipients = collect($recipients)
            ->map(fn ($email) => is_string($email) ? strtolower(trim($email)) : '')
            ->filter(fn ($email) => filter_var($email, FILTER_VALIDATE_EMAIL))
            ->unique()
            ->values()
            ->all();

        if (count($recipients) === 0) {
            throw ValidationException::withMessages([
                'audience_criteria.recipients' => ['Add at least one valid recipient email address.'],
            ]);
        }

        return ['mode' => 'manual', 'recipients' => $recipients];
    }
}
