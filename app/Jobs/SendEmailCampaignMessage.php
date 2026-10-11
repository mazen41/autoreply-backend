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
        if (!$recipient) {
            return;
        }
        $campaign = EmailCampaign::find($recipient->email_campaign_id);
        if (!$campaign) {
            return;
        }
        if ($campaign->paused_at !== null) {
            $this->release(60);
            return;
        }
        if (app(\App\Services\AutomationRunControl::class)->releaseIfPaused($this, (int) $campaign->business_id)) {
            return;
        }

        $claimed = EmailCampaignRecipient::whereKey($this->recipientId)
            ->where('status', 'pending')
            ->update(['status' => 'sending']);
        if ($claimed !== 1) {
            return;
        }
        // The conditional claim was a bulk update; refresh the instance so a
        // retry transition back to `pending` is persisted rather than seen as
        // unchanged stale model state.
        $recipient->refresh();

        $consent = app(\App\Services\ConsentGate::class)->check(
            $campaign->business_id,
            $recipient->email,
            'email',
            'marketing'
        );
        if (!$consent->allowed) {
            $recipient->update(['status' => 'failed', 'error_message' => 'Marketing send blocked: ' . $consent->reason]);
            $campaign->increment('failed_count');
            Log::info('Email campaign recipient blocked by consent gate', [
                'campaign_id' => $campaign->id,
                'recipient_id' => $recipient->id,
                'reason' => $consent->reason,
            ]);
        } else {
            $usage = app(\App\Services\UsageMeter::class)->canUse(
                $campaign->business,
                \App\Services\UsageMeter::OUTBOUND_MESSAGES,
                excludeEmailRecipientId: (int) $recipient->id,
            );
            if (!$usage['allowed']) {
                $reason = 'The business plan limit for monthly outbound messages has been reached.';
                $recipient->update(['status' => 'failed', 'error_message' => $reason]);
                $campaign->increment('failed_count');
                Log::notice('Email campaign recipient blocked by business plan limit', [
                    'business_id' => $campaign->business_id,
                    'campaign_id' => $campaign->id,
                    'recipient_id' => $recipient->id,
                ]);
            } else {
            if ($campaign->paused_at !== null
                || app(\App\Services\AutomationRunControl::class)->isBusinessPaused((int) $campaign->business_id)) {
                $recipient->update(['status' => 'pending']);
                $this->release(60);
                return;
            }
            try {
                Mail::to($recipient->email)->send(new EmailCampaignMail($campaign, $recipient));

                $recipient->update(['status' => 'sent', 'sent_at' => now()]);
                $campaign->increment('delivered_count');
            } catch (\Throwable $e) {
                $statusCode = $this->providerStatusCode($e);
                $transient = $this->isTransientFailure($e, $statusCode);
                $attempt = $this->attempts();
                Log::error('Email campaign send failed', [
                    'campaign_id' => $campaign->id,
                    'recipient_id' => $recipient->id,
                    'exception_type' => get_class($e),
                    'provider_status' => $statusCode,
                    'transient' => $transient,
                    'attempt' => $attempt,
                ]);

                if ($transient && $attempt < $this->tries) {
                    $recipient->update(['status' => 'pending']);
                    $delay = $this->backoff[min(max($attempt - 1, 0), count($this->backoff) - 1)];
                    $this->release($delay);
                    return;
                }

                $recipient->update(['status' => 'failed', 'error_message' => 'Email delivery failed.']);
                $campaign->increment('failed_count');
                $campaign->update(['error_message' => 'One or more email deliveries failed.']);
            }
            }
        }

        // Once every recipient has been attempted, flip the campaign to its final status.
        $remaining = EmailCampaignRecipient::where('email_campaign_id', $campaign->id)
            ->whereIn('status', ['pending', 'sending'])
            ->count();

        if ($remaining === 0) {
            $campaign->refresh();
            $campaign->update([
                'status' => $campaign->delivered_count > 0 ? 'sent' : 'failed',
            ]);
        }
    }

    private function providerStatusCode(\Throwable $exception): ?int
    {
        if (method_exists($exception, 'getResponse') && $exception->getResponse()) {
            $response = $exception->getResponse();
            if (method_exists($response, 'getStatusCode')) {
                return (int) $response->getStatusCode();
            }
        }

        return null;
    }

    private function isTransientFailure(\Throwable $exception, ?int $statusCode): bool
    {
        if (preg_match('/invalid (email|address|recipient)|unknown recipient|mailbox unavailable|recipient rejected|550/i', $exception->getMessage()) === 1) {
            return false;
        }

        if ($statusCode !== null) {
            return $statusCode === 408 || $statusCode === 425 || $statusCode === 429 || $statusCode >= 500;
        }

        if ($exception instanceof \Illuminate\Http\Client\ConnectionException
            || $exception instanceof \Symfony\Component\Mailer\Exception\TransportExceptionInterface) {
            return true;
        }

        return preg_match('/timeout|timed out|connection reset|temporarily unavailable|rate.?limit/i', $exception->getMessage()) === 1;
    }

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addDays(14);
    }

    public function failed(\Throwable $e): void
    {
        $recipient = EmailCampaignRecipient::find($this->recipientId);
        if (!$recipient) {
            return;
        }

        $campaign = EmailCampaign::find($recipient->email_campaign_id);

        if (in_array($recipient->status, ['pending', 'sending'], true)) {
            $recipient->update([
                'status' => 'failed',
                'error_message' => 'Email delivery failed.',
            ]);

            if ($campaign) {
                $campaign->increment('failed_count');
                $campaign->update(['error_message' => 'One or more email deliveries failed.']);
            }
        }

        if ($campaign) {
            $remaining = EmailCampaignRecipient::where('email_campaign_id', $campaign->id)
                ->whereIn('status', ['pending', 'sending'])
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
