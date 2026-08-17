<?php

namespace App\Jobs;

use App\Mail\EmailCampaignMail;
use App\Models\EmailCampaign;
use App\Models\EmailCampaignRecipient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendEmailCampaignMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [60, 300, 900];

    public function __construct(public int $recipientId) {}

    public function handle(): void
    {
        $recipient = EmailCampaignRecipient::find($this->recipientId);
        if (!$recipient || $recipient->status !== 'pending') {
            return;
        }

        $campaign = EmailCampaign::find($recipient->email_campaign_id);
        if (!$campaign) {
            return;
        }

        try {
            Mail::to($recipient->email)->send(new EmailCampaignMail($campaign, $recipient));

            $recipient->update(['status' => 'sent', 'sent_at' => now()]);
            $campaign->increment('delivered_count');
        } catch (\Throwable $e) {
            Log::error('Email campaign send failed', [
                'campaign_id' => $campaign->id,
                'recipient_id' => $recipient->id,
                'error' => $e->getMessage(),
            ]);
            $recipient->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
            $campaign->increment('failed_count');
            $campaign->update(['error_message' => $e->getMessage()]);
        }

        // Once every recipient has been attempted, flip the campaign to its final status.
        $remaining = EmailCampaignRecipient::where('email_campaign_id', $campaign->id)
            ->where('status', 'pending')
            ->count();

        if ($remaining === 0) {
            $campaign->refresh();
            $campaign->update([
                'status' => $campaign->delivered_count > 0 ? 'sent' : 'failed',
            ]);
        }
    }

    public function failed(\Throwable $e): void
    {
        $recipient = EmailCampaignRecipient::find($this->recipientId);
        if (!$recipient) {
            return;
        }

        $campaign = EmailCampaign::find($recipient->email_campaign_id);

        if ($recipient->status === 'pending') {
            $recipient->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            if ($campaign) {
                $campaign->increment('failed_count');
                $campaign->update(['error_message' => $e->getMessage()]);
            }
        }

        if ($campaign) {
            $remaining = EmailCampaignRecipient::where('email_campaign_id', $campaign->id)
                ->where('status', 'pending')
                ->count();

            if ($remaining === 0) {
                $campaign->refresh();
                $campaign->update([
                    'status' => $campaign->delivered_count > 0 ? 'sent' : 'failed',
                ]);
            }
        }
    }
}
