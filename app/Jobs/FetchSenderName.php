<?php

namespace App\Jobs;

use App\Models\Channel;
use App\Models\Conversation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FetchSenderName implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 2; // Bug 8: reduced from 3 to 2
    public $backoff = [5, 10]; // Progressive backoff

    public function __construct(
        public int $conversationId,
        public int $channelId,
        public string $senderId
    ) {
    }

    public function handle(): void
    {
        $channel = Channel::find($this->channelId);
        if (!$channel) {
            Log::warning('FetchSenderName: channel not found', ['channel_id' => $this->channelId]);
            return;
        }

        // Bug 8: WhatsApp sender IDs are phone numbers, not Facebook PSIDs.
        // Calling Graph API with a phone number always returns 400/404.
        if ($channel->type === 'whatsapp') {
            return;
        }

        $conversation = Conversation::find($this->conversationId);
        if (!$conversation) {
            Log::warning('FetchSenderName: conversation not found', ['conversation_id' => $this->conversationId]);
            return;
        }

        // If we already have a name, skip
        if (!empty($conversation->sender_name)) {
            Log::info('FetchSenderName: conversation already has sender name', ['conversation_id' => $this->conversationId]);
            return;
        }

        try {
            // The Channel model's accessor already decrypts access_token — do NOT
            // call decrypt() again or it will throw "The payload is invalid."
            $accessToken = $channel->access_token;
            
            $response = Http::timeout(8)
                ->get("https://graph.facebook.com/v19.0/{$this->senderId}", [
                    'fields'       => 'name',
                    'access_token' => $accessToken,
                ]);

            if (!$response->successful()) {
                Log::warning('FetchSenderName: Graph API request failed', [
                    'sender_id' => $this->senderId,
                    'status'    => $response->status(),
                    'body'      => $response->json(),
                ]);
                
                // If it's a 400/404, it's a permanent failure (e.g. user blocked app, invalid PSID).
                // Throw an exception for 5xx to trigger Laravel's normal retry mechanism.
                if ($response->serverError()) {
                    throw new \Exception('Graph API returned server error: ' . $response->status());
                }
                return;
            }

            $data = $response->json();
            $name = $data['name'] ?? '';

            if ($name !== '') {
                $conversation->sender_name = $name;
                $conversation->save();

                Log::info('FetchSenderName: successfully updated sender name', [
                    'conversation_id' => $this->conversationId,
                    'sender_name' => $name,
                ]);
            }
        } catch (\Exception $e) {
            Log::error('FetchSenderName: exception', [
                'error' => $e->getMessage(),
                'sender_id' => $this->senderId,
                'conversation_id' => $this->conversationId,
            ]);
            
            // Bug 8: Removed $this->release(30) to prevent double-retry loops.
            // If we actually want to retry, we re-throw the exception to let Laravel handle it.
            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('FetchSenderName job failed permanently', [
            'conversation_id' => $this->conversationId,
            'channel_id' => $this->channelId,
            'sender_id' => $this->senderId,
            'error' => $exception->getMessage(),
        ]);
    }
}