<?php

namespace App\Mail;

use App\Models\EmailCampaign;
use App\Models\EmailCampaignRecipient;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class EmailCampaignMail extends Mailable
{
    // Deliberately NOT ShouldQueue: the outer SendEmailCampaignMessage job is
    // already dispatched to the queue per-recipient, and it needs to know
    // synchronously whether Mail::send() succeeded so it can record an
    // honest delivered/failed count. If this Mailable also implemented
    // ShouldQueue, Mail::send() would silently re-queue it instead of
    // sending, and the job would mark it "delivered" before it was ever
    // actually sent.
    use Queueable, SerializesModels;

    public function __construct(
        public EmailCampaign $campaign,
        public EmailCampaignRecipient $recipient,
    ) {}

    public function build()
    {
        $html = $this->rewriteLinksForClickTracking($this->campaign->content);

        $trackingKey = $this->recipient->tracking_token ?: (string) $this->recipient->id;
        $trackingUrl = url('/api/email-campaigns/track/open/' . $trackingKey);
        $html .= '<img src="' . e($trackingUrl) . '" width="1" height="1" style="display:none" alt="" />';

        return $this->subject($this->campaign->subject)
            ->html($html);
    }

    private function rewriteLinksForClickTracking(string $html): string
    {
        return preg_replace_callback(
            '/href=(["\'])(https?:\/\/[^"\']+)\1/i',
            function (array $matches): string {
                $trackingKey = $this->recipient->tracking_token ?: (string) $this->recipient->id;
                $trackingUrl = url('/api/email-campaigns/track/click/' . $trackingKey)
                    . '?url=' . rawurlencode($matches[2]);

                return 'href=' . $matches[1] . e($trackingUrl) . $matches[1];
            },
            $html
        ) ?? $html;
    }
}
