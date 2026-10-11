<?php

namespace App\Services;

use App\Jobs\SendCampaignMessage;
use App\Models\Campaign;
use App\Models\CampaignLog;
use App\Models\Channel;
use App\Models\Conversation;
use Illuminate\Support\Facades\Log;

class CampaignLaunchService
{
    /**
     * Launch a campaign only when its current status is explicitly allowed.
     * The status update is an atomic claim so overlapping manual/scheduled
     * launches cannot enqueue duplicate recipient jobs.
     *
     * @return array{success: bool, campaign?: Campaign, error?: string, status?: int}
     */
    public function launch(int|string $businessId, int|string $campaignId, array $allowedStatuses): array
    {
        $campaign = Campaign::where('business_id', $businessId)->findOrFail($campaignId);
        $channel = Channel::where('id', $campaign->channel_id)
            ->where('business_id', $businessId)
            ->first();

        if (!$channel) {
            return $this->failure('Campaign channel does not belong to this business.', 422);
        }

        if (!in_array($campaign->status, $allowedStatuses, true)) {
            return $this->failure('Campaign is not in a launchable status', 400);
        }

        $whatsappValidationError = $this->whatsappCampaignValidationError(
            $channel,
            $businessId,
            $campaign->whatsapp_cloud_template_id
        );
        if ($whatsappValidationError) {
            return $this->failure($whatsappValidationError, 422);
        }

        $claimed = Campaign::where('id', $campaignId)
            ->where('business_id', $businessId)
            ->whereIn('status', $allowedStatuses)
            ->update(['status' => 'sending', 'sent_at' => now()]);

        if ($claimed === 0) {
            return $this->failure('Campaign is not in a launchable status', 400);
        }

        $campaign->refresh();
        $query = Conversation::where('channel_id', $campaign->channel_id)
            ->where('business_id', $businessId);

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
        if ($conversations->isEmpty()) {
            $error = 'No matching recipients found for this campaign\'s audience filters.';
            $campaign->update([
                'total_recipients' => 0,
                'status' => 'failed',
                'error_message' => $error,
            ]);

            return $this->failure($error, 400);
        }

        $campaign->update(['total_recipients' => $conversations->count()]);
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

        return ['success' => true, 'campaign' => $campaign->fresh()];
    }

    private function whatsappCampaignValidationError(Channel $channel, int|string $businessId, mixed $templateId): ?string
    {
        if ($channel->type !== 'whatsapp') {
            return null;
        }

        if (($channel->metadata['whatsapp_provider'] ?? null) !== 'cloud') {
            return 'WhatsApp campaigns are disabled for unofficial Evolution connections.';
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

    private function failure(string $error, int $status): array
    {
        return ['success' => false, 'error' => $error, 'status' => $status];
    }
}
