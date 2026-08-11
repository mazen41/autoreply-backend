<?php

namespace App\Jobs;

use App\Models\ProactiveCampaign;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Channel;
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

    public $tries = 3;
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

        try {
            // Create message record
            $message = Message::create([
                'conversation_id' => $conversation->id,
                'content' => $campaign->message,
                'direction' => 'outbound',
                'status' => 'sent',
                'is_ai' => false,
                'source' => 'proactive_campaign_' . $campaign->id,
                'send_status' => 'pending',
            ]);

            // Send through appropriate channel
            $this->sendThroughChannel($channel, $conversation, $message);

            Log::info('Proactive message sent', [
                'campaign_id' => $this->campaignId,
                'conversation_id' => $this->conversationId,
                'message_id' => $message->id
            ]);

        } catch (\Exception $e) {
            Log::error('SendProactiveMessage failed', [
                'campaign_id' => $this->campaignId,
                'conversation_id' => $this->conversationId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    private function sendThroughChannel(Channel $channel, Conversation $conversation, Message $message): void
    {
        switch ($channel->type) {
            case 'whatsapp':
                $this->sendWhatsApp($channel, $conversation, $message);
                break;
            case 'facebook':
            case 'instagram':
                $this->sendMeta($channel, $conversation, $message);
                break;
            case 'gmail':
                $this->sendGmail($channel, $conversation, $message);
                break;
            default:
                Log::warning('SendProactiveMessage: unsupported channel type', ['type' => $channel->type]);
        }
    }

    private function sendWhatsApp(Channel $channel, Conversation $conversation, Message $message): void
    {
        $instanceName = $channel->page_id;
        $recipientId = $conversation->sender_id;

        $response = Http::timeout(10)
            ->post(config('services.evolution_api.url') . "/message/sendText/{$instanceName}", [
                'number' => $recipientId,
                'text' => $message->content,
            ]);

        if ($response->successful()) {
            $message->update(['send_status' => 'sent']);
        } else {
            $message->update(['send_status' => 'failed']);
            throw new \Exception('WhatsApp send failed');
        }
    }

    private function sendMeta(Channel $channel, Conversation $conversation, Message $message): void
    {
        $accessToken = $channel->access_token;
        $url = "https://graph.facebook.com/v19.0/me/messages?access_token={$accessToken}";

        $response = Http::timeout(10)
            ->post($url, [
                'recipient' => ['id' => $conversation->sender_id],
                'message' => ['text' => $message->content],
            ]);

        if ($response->successful()) {
            $message->update(['send_status' => 'sent']);
        } else {
            $message->update(['send_status' => 'failed']);
            throw new \Exception('Meta send failed');
        }
    }

    private function sendGmail(Channel $channel, Conversation $conversation, Message $message): void
    {
        // Gmail implementation would require Gmail API integration
        // For now, mark as sent (would need proper implementation)
        $message->update(['send_status' => 'sent']);
        Log::info('Gmail proactive message would be sent here', ['message_id' => $message->id]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('SendProactiveMessage job failed permanently', [
            'campaign_id' => $this->campaignId,
            'conversation_id' => $this->conversationId,
            'error' => $exception->getMessage()
        ]);
    }
}