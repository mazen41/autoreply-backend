<?php

namespace App\Mail;

use App\Models\EmailCampaign;
use App\Models\EmailCampaignRecipient;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

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
        if (!$this->recipient->tracking_token) {
            $this->recipient->tracking_token = Str::random(48);
            $this->recipient->save();
        }

        $html = $this->rewriteLinksForClickTracking($this->campaign->content);

        $trackingKey = $this->recipient->tracking_token;
        $trackingUrl = url('/api/email-campaigns/track/open/' . $trackingKey);
        $html .= '<img src="' . e($trackingUrl) . '" width="1" height="1" style="display:none" alt="" />';
        $unsubscribeUrl = url('/api/email-campaigns/unsubscribe/' . $trackingKey);
        $html .= '<p><a href="' . e($unsubscribeUrl) . '">Unsubscribe from marketing emails</a></p>';

        $this->withSymfonyMessage(function ($message) use ($unsubscribeUrl): void {
            $headers = $message->getHeaders();
            $headers->addTextHeader('List-Unsubscribe', '<' . $unsubscribeUrl . '>');
            $headers->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
        });

        return $this->subject($this->campaign->subject)
            ->html($html);
    }

    private function rewriteLinksForClickTracking(string $html): string
    {
        $links = [];
        $rewritten = preg_replace_callback(
            '/href=(["\'])(https?:\/\/[^"\']+)\1/i',
            function (array $matches) use (&$links): string {
                $trackingKey = $this->recipient->tracking_token;
                $destination = html_entity_decode($matches[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $linkToken = hash_hmac('sha256', $trackingKey . "\n" . $destination, (string) config('app.key'));
                $links[$linkToken] = $destination;
                $trackingUrl = url('/api/email-campaigns/track/click/' . $trackingKey)
                    . '/' . $linkToken;

                return 'href=' . $matches[1] . e($trackingUrl) . $matches[1];
            },
            $html
        ) ?? $html;

        $this->recipient->forceFill(['tracking_links' => $links])->save();

        return $rewritten;
    }
}
