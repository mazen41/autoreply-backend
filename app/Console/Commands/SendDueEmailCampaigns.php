<?php

namespace App\Console\Commands;

use App\Jobs\SendEmailCampaignMessage;
use App\Models\EmailCampaign;
use App\Services\EmailCampaignAudienceService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SendDueEmailCampaigns extends Command
{
    protected $signature = 'email-campaigns:send-due';
    protected $description = 'Send scheduled email campaigns whose scheduled_at time has passed';

    public function handle(EmailCampaignAudienceService $audience): int
    {
        $due = EmailCampaign::where('status', 'scheduled')
            ->where('scheduled_at', '<=', now())
            ->get();

        foreach ($due as $campaign) {
            $audience->buildRecipients($campaign);

            if ($campaign->total_recipients === 0) {
                $this->warn("Campaign #{$campaign->id} has no recipients, skipping");
                $campaign->update([
                    'status' => 'failed',
                    'error_message' => 'No valid recipients found when scheduled campaign became due.',
                ]);
                continue;
            }

            $campaign->update(['status' => 'sending', 'sent_at' => now()]);

            foreach ($campaign->recipients()->where('status', 'pending')->pluck('id') as $recipientId) {
                SendEmailCampaignMessage::dispatch($recipientId);
            }

            Log::info('Scheduled email campaign dispatched', ['campaign_id' => $campaign->id]);
            $this->info("Dispatched campaign #{$campaign->id}");
        }

        return self::SUCCESS;
    }
}
