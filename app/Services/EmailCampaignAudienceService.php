<?php

namespace App\Services;

use App\Models\BusinessProfile;
use App\Models\Conversation;
use App\Models\EmailCampaign;
use App\Models\EmailCampaignRecipient;
use Illuminate\Support\Str;

class EmailCampaignAudienceService
{
    public function resolveEmails(BusinessProfile $business): array
    {
        return Conversation::where('business_id', $business->id)
            ->whereHas('channel', fn ($q) => $q->where('type', 'gmail'))
            ->pluck('sender_id')
            ->filter(fn ($senderId) => filter_var($senderId, FILTER_VALIDATE_EMAIL))
            ->unique()
            ->values()
            ->all();
    }

    public function resolveCampaignEmails(EmailCampaign $campaign): array
    {
        $criteria = $campaign->audience_criteria ?? [];
        $manualRecipients = $criteria['recipients'] ?? [];

        if (is_array($manualRecipients) && count($manualRecipients) > 0) {
            return collect($manualRecipients)
                ->map(fn ($email) => is_string($email) ? strtolower(trim($email)) : '')
                ->filter(fn ($email) => filter_var($email, FILTER_VALIDATE_EMAIL))
                ->unique()
                ->values()
                ->all();
        }

        return $this->resolveEmails($campaign->business);
    }

    public function buildRecipients(EmailCampaign $campaign, bool $refresh = false): void
    {
        if ($refresh) {
            $campaign->recipients()->delete();
            $campaign->update([
                'total_recipients' => 0,
                'delivered_count' => 0,
                'opened_count' => 0,
                'clicked_count' => 0,
                'failed_count' => 0,
                'error_message' => null,
            ]);
        } elseif ($campaign->recipients()->exists()) {
            return;
        }

        $emails = $this->resolveCampaignEmails($campaign);

        foreach ($emails as $email) {
            EmailCampaignRecipient::create([
                'email_campaign_id' => $campaign->id,
                'tracking_token' => Str::random(48),
                'email' => $email,
                'status' => 'pending',
            ]);
        }

        $campaign->update(['total_recipients' => count($emails)]);
    }
}
