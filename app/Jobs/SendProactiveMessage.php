<?php

namespace App\Jobs;

use App\Models\ProactiveCampaign;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Channel;
use App\Services\ConsentGate;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SendProactiveMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Provider sends do not expose an idempotency key here. Automatic retries
    // can therefore send duplicate marketing messages after an ambiguous
    // network timeout; record the outcome and require an explicit retry.
    public $tries = 1;
    public $backoff = [10, 30, 60];

    public function __construct(
        public int $campaignId,
        public int $conversationId
    ) {
        $this->onQueue('proactive');
    }

    public function handle(): void
    {
        $campaign = ProactiveCampaign::find($this->campaignId);
        if (!$campaign) {
            Log::warning('SendProactiveMessage: campaign not found', ['campaign_id' => $this->campaignId]);
            return;
        }
        if (app(\App\Services\AutomationRunControl::class)->releaseIfPaused($this, (int) $campaign->business_id)) {
            return;
        }

        $conversation = Conversation::with('channel')->find($this->conversationId);
        if (!$conversation) {
            Log::warning('SendProactiveMessage: conversation not found', ['conversation_id' => $this->conversationId]);
            return;
        }

        $channel = $conversation->channel;
        if (!$channel || $channel->status !== 'connected') {
            Log::warning('SendProactiveMessage: channel not connected', ['channel_id' => $channel?->id]);
            return;
        }

        $consent = app(ConsentGate::class)->check(
            $conversation->business_id,
            $channel->type === 'gmail' ? ($conversation->sender_email ?? '') : $conversation->sender_id,
            $channel->type,
            'marketing'
        );
        if (!$consent->allowed) {
            $blocked = Message::create([
                'conversation_id' => $conversation->id,
                'content' => $campaign->message,
                'direction' => 'outbound',
                'status' => 'blocked',
                'is_ai' => false,
                'source' => 'proactive_campaign_' . $campaign->id,
                'send_status' => 'blocked',
                'error_details' => mb_substr('Marketing consent blocked this send: ' . $consent->reason, 0, 2000),
            ]);
            if ($channel->user_id) {
                broadcast(new \App\Events\MessageReceived($blocked, $conversation, $channel->user_id));
            }
            Log::info('SendProactiveMessage: marketing consent blocked recipient', [
                'campaign_id' => $campaign->id,
                'conversation_id' => $conversation->id,
                'channel_id' => $channel->id,
                'reason' => $consent->reason,
                'message_id' => $blocked->id,
            ]);
            return;
        }

        $message = null;
        try {
            $usage = app(\App\Services\UsageMeter::class)->canUse(
                $conversation->business,
                \App\Services\UsageMeter::OUTBOUND_MESSAGES,
            );
            if (!$usage['allowed']) {
                $reason = 'The business plan limit for monthly outbound messages has been reached.';
                $blocked = Message::create([
                    'conversation_id' => $conversation->id,
                    'content' => $campaign->message,
                    'direction' => 'outbound',
                    'status' => 'blocked',
                    'is_ai' => false,
                    'source' => 'proactive_campaign_' . $campaign->id,
                    'send_status' => 'blocked',
                    'error_details' => $reason,
                ]);
                if ($channel->user_id) {
                    broadcast(new \App\Events\MessageReceived($blocked, $conversation, $channel->user_id));
                }
                Log::notice('SendProactiveMessage: outbound plan limit blocked message', [
                    'business_id' => $conversation->business_id,
                    'campaign_id' => $campaign->id,
                    'conversation_id' => $conversation->id,
                ]);
                return;
            }

            // Create message record
            $message = Message::create([
                'conversation_id' => $conversation->id,
                'content' => $campaign->message,
                'direction' => 'outbound',
                'status' => 'queued',
                'is_ai' => false,
                'source' => 'proactive_campaign_' . $campaign->id,
                'send_status' => 'pending',
            ]);

            // The business may have been paused during consent/usage checks.
            if (app(\App\Services\AutomationRunControl::class)->isBusinessPaused((int) $campaign->business_id)) {
                $message->delete();
                $this->release(60);
                return;
            }

            // Send through appropriate channel
            $sent = $this->sendThroughChannel($channel, $conversation, $message);

            Log::info($sent ? 'Proactive message sent' : 'Proactive message was not sent', [
                'campaign_id' => $this->campaignId,
                'conversation_id' => $this->conversationId,
                'message_id' => $message->id,
                'send_status' => $message->fresh()->send_status,
            ]);

        } catch (\Exception $e) {
            Log::error('SendProactiveMessage failed', [
                'campaign_id' => $this->campaignId,
                'conversation_id' => $this->conversationId,
                'exception_type' => get_class($e),
            ]);
            $message?->update([
                'status' => 'failed',
                'send_status' => 'failed',
                'delivery_status' => 'failed',
                'error_details' => mb_substr('Provider send could not be confirmed: ' . $e->getMessage(), 0, 2000),
            ]);
        }
    }

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addDays(14);
    }

    private function sendThroughChannel(Channel $channel, Conversation $conversation, Message $message): bool
    {
        switch ($channel->type) {
            case 'whatsapp':
                return $this->sendWhatsApp($channel, $conversation, $message);
            case 'facebook':
            case 'instagram':
                return $this->sendMeta($channel, $conversation, $message);
            case 'gmail':
                return app(\App\Services\ChannelMessageService::class)->sendMessage($channel, $conversation, $message);
            default:
                Log::warning('SendProactiveMessage: unsupported channel type', ['type' => $channel->type]);
                $message->update([
                    'status' => 'failed', 'send_status' => 'failed', 'delivery_status' => 'failed',
                    'error_details' => 'unsupported_channel_type',
                ]);
                return false;
        }
    }

    private function sendWhatsApp(Channel $channel, Conversation $conversation, Message $message): bool
    {
        // Proactive sends are outside a customer-initiated reply flow. They
        // must use an approved Cloud template; this job has no template
        // selection/configuration yet, so fail closed instead of sending
        // arbitrary free-form traffic through an unofficial WA provider.
        $reason = 'WhatsApp proactive sends require an approved Cloud API template and are not enabled for this campaign.';
        $message->update([
            'status' => 'blocked', 'send_status' => 'blocked',
            'error_details' => $reason,
        ]);
        return false;
    }

    private function sendMeta(Channel $channel, Conversation $conversation, Message $message): bool
    {
        $accessToken = $channel->access_token;
        $url = 'https://graph.facebook.com/' . config('services.meta.graph_version', 'v26.0') . "/me/messages?access_token={$accessToken}";

        $response = Http::timeout(10)
            ->post($url, [
                'recipient' => ['id' => $conversation->sender_id],
                'message' => ['text' => $message->content],
            ]);

        if ($response->successful()) {
            $message->update([
                'status' => 'sent', 'send_status' => 'sent', 'delivery_status' => 'sent',
                'error_details' => null,
                'metadata' => array_merge($message->metadata ?? [], [
                    'platform_message_id' => $response->json('message_id'),
                ]),
            ]);
            return true;
        } else {
            $reason = (string) ($response->json('error.message') ?? 'Meta provider rejected the message.');
            $message->update([
                'status' => 'failed', 'send_status' => 'failed', 'delivery_status' => 'failed',
                'error_details' => mb_substr($reason, 0, 2000),
            ]);
            return false;
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('SendProactiveMessage job failed permanently', [
            'campaign_id' => $this->campaignId,
            'conversation_id' => $this->conversationId,
            'exception_type' => get_class($exception),
        ]);
    }
}
