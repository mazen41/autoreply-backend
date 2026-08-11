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

    public $tries = 3;
    public $backoff = [5, 10, 30]; // Progressive backoff

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
            // Decrypt the access token
            $accessToken = decrypt($channel->access_token);
            
            $response = Http::timeout(8)
                ->get("https://graph.facebook.com/v19.0/{$this->senderId}", [
                    'fields'       => 'first_name,last_name,name',
                    'access_token' => $accessToken,
                ]);

            if (!$response->successful()) {
                Log::warning('FetchSenderName: Graph API request failed', [
                    'sender_id' => $this->senderId,
                    'status'    => $response->status(),
                    'body'      => $response->json(),
                ]);
                return;
            }

            $data = $response->json();
            $name = $data['name'] ?? trim(($data['first_name'] ?? '') . ' ' . ($data['last_name'] ?? ''));

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
            
            // Mark this job as failed for retry
            $this->release(30);
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