<?php

namespace App\Jobs;

use App\Models\Message;
use App\Models\Channel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RetryFailedMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $backoff = [60, 300, 900]; // 1min, 5min, 15min
    public $timeout = 120;

    public function __construct(public int $messageId)
    {
        $this->onQueue('messages');
    }

    public function handle(): void
    {
        $message = Message::with(['conversation.channel'])->find($this->messageId);

        if (!$message) {
            Log::warning('RetryFailedMessage: message not found', ['message_id' => $this->messageId]);
            return;
        }

        if ($message->delivery_status === 'sent' || $message->delivery_status === 'delivered') {
            Log::info('RetryFailedMessage: message already sent', ['message_id' => $this->messageId]);
            return;
        }

        if ($message->retry_count >= 3) {
            Log::warning('RetryFailedMessage: max retries reached', ['message_id' => $this->messageId]);
            $message->update([
                'delivery_status' => 'failed',
                'error_details' => 'Max retries exceeded',
            ]);
            return;
        }

        $channel = $message->conversation->channel;

        if (!$channel || $channel->status !== 'connected') {
            Log::warning('RetryFailedMessage: channel not connected', ['channel_id' => $channel?->id]);
            $this->release(300); // Retry after 5 minutes
            return;
        }

        try {
            $success = $this->sendMessage($channel, $message);

            if ($success) {
                $message->update([
                    'delivery_status' => 'sent',
                    'retry_count' => $message->retry_count + 1,
                    'last_retry_at' => now(),
                ]);

                Log::info('RetryFailedMessage: message sent successfully', ['message_id' => $this->messageId]);
            } else {
                $message->update([
                    'retry_count' => $message->retry_count + 1,
                    'last_retry_at' => now(),
                ]);

                $this->release(300); // Retry after 5 minutes
            }
        } catch (\Exception $e) {
            Log::error('RetryFailedMessage: exception', [
                'message_id' => $this->messageId,
                'error' => $e->getMessage(),
            ]);

            $message->update([
                'retry_count' => $message->retry_count + 1,
                'last_retry_at' => now(),
                'error_details' => $e->getMessage(),
            ]);

            $this->release(300);
        }
    }

    private function sendMessage(Channel $channel, Message $message): bool
    {
        $channelType = $channel->type;
        $senderId = $message->conversation->sender_id;

        switch ($channelType) {
            case 'facebook':
            case 'instagram':
                return $this->sendMetaMessage($channel, $senderId, $message->content);
            
            case 'whatsapp':
                return $this->sendWhatsAppMessage($channel, $senderId, $message->content);
            
            default:
                Log::warning('RetryFailedMessage: unsupported channel type', ['channel_type' => $channelType]);
                return false;
        }
    }

    private function sendMetaMessage(Channel $channel, string $recipientId, string $content): bool
    {
        $pageAccessToken = $channel->page_access_token;
        $pageId = $channel->page_id;

        $response = Http::post("https://graph.facebook.com/v18.0/{$pageId}/messages", [
            'recipient' => ['id' => $recipientId],
            'message' => ['text' => $content],
        ], [
            'headers' => [
                'Authorization' => "Bearer {$pageAccessToken}",
            ],
        ]);

        return $response->successful();
    }

    private function sendWhatsAppMessage(Channel $channel, string $recipientId, string $content): bool
    {
        // WhatsApp implementation using Evolution API
        $evolutionService = new \App\Services\EvolutionApiService();
        return $evolutionService->sendMessage($channel, $recipientId, $content);
    }
}
