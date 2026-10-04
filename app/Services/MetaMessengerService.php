<?php

namespace App\Services;

use App\Models\Channel;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Send outbound messages through the Meta Graph Send API
 * (graph.facebook.com/v19.0/me/messages).
 *
 * This is the SINGLE sending implementation for both Facebook Messenger and
 * Instagram DMs (Instagram uses the same endpoint with the page/account token).
 * Extracted from ProcessAutoReply::sendFacebookReply() so sequences and any
 * future outbound flow reuse the exact same wire behavior:
 *   • images first, then the text message
 *   • the Channel accessor decrypts access_token — never decrypt() again
 *   • returns true only when the TEXT send got a 2xx (image failures are
 *     logged but do not fail the text send — matches existing behavior)
 */
class MetaMessengerService
{
    public function sendText(Channel $channel, string $recipientId, string $message, array $images = []): bool
    {
        $accessToken = $channel->access_token;
        $baseUrl = "https://graph.facebook.com/v19.0/me/messages?access_token={$accessToken}";

        foreach ($images as $imageUrl) {
            $imageResponse = Http::timeout(10)->post($baseUrl, [
                'recipient' => ['id' => $recipientId],
                'message' => [
                    'attachment' => [
                        'type' => 'image',
                        'payload' => [
                            'url' => $imageUrl,
                            'is_reusable' => true,
                        ],
                    ],
                ],
            ]);

            if (!$imageResponse->successful()) {
                Log::error('MetaMessengerService: image send failed', [
                    'channel_id' => $channel->id,
                    'channel_type' => $channel->type,
                    'status' => $imageResponse->status(),
                    'body' => $imageResponse->json(),
                    'recipient' => $recipientId,
                ]);
            }
        }

        $response = Http::timeout(10)
            ->post($baseUrl, [
                'recipient' => ['id' => $recipientId],
                'message' => ['text' => $message],
            ]);

        if (!$response->successful()) {
            Log::error('MetaMessengerService: text send failed', [
                'channel_id' => $channel->id,
                'channel_type' => $channel->type,
                'status' => $response->status(),
                'body' => $response->json(),
                'recipient' => $recipientId,
            ]);
        }

        return $response->successful();
    }
}
