<?php

namespace App\Services;

use App\Models\Channel;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Send outbound messages through the Meta Graph Send API
 * (versioned Meta Graph Send API).
 *
 * This is the SINGLE sending implementation for both Facebook Messenger and
 * Instagram DMs (Instagram uses the same endpoint with the page/account token).
 * Extracted from ProcessAutoReply::sendFacebookReply() so sequences and any
 * future outbound flow reuse the exact same wire behavior:
 *   • images first, then the text message
 *   • the Channel accessor decrypts access_token — never decrypt() again
 *   • reports partial failure when any requested image or the text is rejected
 */
class MetaMessengerService
{
    public function sendText(Channel $channel, string $recipientId, string $message, array $images = []): bool
    {
        return $this->sendTextWithResult($channel, $recipientId, $message, $images)['success'];
    }

    /** Return the provider result so callers can persist status, ID, and errors. */
    public function sendTextWithResult(Channel $channel, string $recipientId, string $message, array $images = []): array
    {
        $accessToken = $channel->access_token;
        $baseUrl = 'https://graph.facebook.com/' . config('services.meta.graph_version', 'v26.0') . "/me/messages?access_token={$accessToken}";
        $messageIds = [];
        $firstError = null;

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
                $firstError ??= $imageResponse->json('error.message') ?: 'image_rejected';
                Log::error('MetaMessengerService: image send failed', [
                    'channel_id' => $channel->id,
                    'channel_type' => $channel->type,
                    'status' => $imageResponse->status(),
                ]);
            } elseif ($imageMessageId = $imageResponse->json('message_id')) {
                $messageIds[] = (string) $imageMessageId;
            }
        }

        $response = Http::timeout(10)
            ->post($baseUrl, [
                'recipient' => ['id' => $recipientId],
                'message' => ['text' => $message],
            ]);

        if (!$response->successful()) {
            $firstError ??= $response->json('error.message') ?: 'text_rejected';
            Log::error('MetaMessengerService: text send failed', [
                'channel_id' => $channel->id,
                'channel_type' => $channel->type,
                'status' => $response->status(),
            ]);
        }

        $textMessageId = $response->successful() ? $response->json('message_id') : null;
        if ($textMessageId) {
            $messageIds[] = (string) $textMessageId;
        }

        return [
            'success' => $response->successful() && $firstError === null,
            'message_id' => $textMessageId ? (string) $textMessageId : null,
            'message_ids' => $messageIds,
            'error' => $firstError,
            'status_code' => $response->status(),
        ];
    }
}
