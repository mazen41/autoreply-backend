<?php

namespace App\Console\Commands;

use App\Jobs\SendEmailCampaignMessage;
use App\Models\EmailCampaign;
use App\Services\EmailCampaignAudienceService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SendDueEmailCampaigns extends Command
{
    protected $signature = 'email-campaigns:send-due';
    protected $description = 'Send scheduled email campaigns whose scheduled_at time has passed';

    public function handle(EmailCampaignAudienceService $audience): int
    {
        $dueIds = EmailCampaign::where('status', 'scheduled')
            ->where('scheduled_at', '<=', now())
            ->pluck('id');

        foreach ($dueIds as $campaignId) {
            // Re-fetch and lock the row inside its own transaction. If the
            // campaign was edited, cancelled, or deleted by the user in the
            // moment between the query above and now, this row lock + status
            // re-check means we won't send stale content/recipients or send
            // a campaign the user just cancelled.
            DB::transaction(function () use ($campaignId, $audience) {
                $campaign = EmailCampaign::where('id', $campaignId)
                    ->where('status', 'scheduled')
                    ->lockForUpdate()
                    ->first();

                if (!$campaign) {
                    // No longer scheduled — edited back to draft, cancelled,
                    // or deleted concurrently. Nothing to do.
                    return;
                }

                $audience->buildRecipients($campaign);

                if ($campaign->total_recipients === 0) {
                    $this->warn("Campaign #{$campaign->id} has no recipients, skipping");
                    $campaign->update([
                        'status' => 'failed',
                        'error_message' => 'No valid recipients found when scheduled campaign became due.',
                    ]);
                    return;
                }

                $campaign->update(['status' => 'sending', 'sent_at' => now()]);

                $recipientIds = $campaign->recipients()
                    ->where('status', 'pending')
                    ->pluck('id')
                    ->all();
                $campaignId = $campaign->id;

                // Queue drivers are not configured for after-commit dispatch in
                // every environment. Register explicitly so workers cannot
                // process recipient jobs before the campaign transaction commits.
                DB::afterCommit(function () use ($recipientIds, $campaignId) {
                    foreach ($recipientIds as $recipientId) {
                        SendEmailCampaignMessage::dispatch($recipientId);
                    }

                    Log::info('Scheduled email campaign dispatched', ['campaign_id' => $campaignId]);
                    $this->info("Dispatched campaign #{$campaignId}");
                });
            });
        }

        return self::SUCCESS;
    }
}
