<?php

namespace App\Http\Controllers;

use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Services\IncomingMediaStorage;

class TelegramController extends Controller
{
    public function connect(Request $request)
    {
        $businessProfile = app(\App\Services\ActiveBusiness::class)->resolve($request, permission: 'write');
        $botToken = $request->bot_token ?? env('TELEGRAM_BOT_TOKEN');
        
        if (!$botToken) {
            return response()->json(['error' => 'Bot token is required'], 422);
        }

        $userId = auth()->id();

        // Verify bot token by calling Telegram getMe API
        try {
            $response = Http::timeout(10)->get("https://api.telegram.org/bot{$botToken}/getMe");

            if (!$response->successful()) {
                Log::error('Telegram bot token verification failed', [
                    'user_id' => $userId,
                    'status' => $response->status(),
                ]);
                return response()->json(['error' => 'Invalid bot token. Please check and try again.'], 422);
            }

            $botInfo = $response->json();
            $botUsername = $botInfo['result']['username'] ?? null;
            $botName = $botInfo['result']['first_name'] ?? 'Telegram Bot';

            // Save channel FIRST so we have its id, then register the webhook
            // against a channel-specific URL. The old URL
            // (/api/telegram/webhook/{userId} with no channel id) cannot tell
            // two bots for the same user apart, so it must not be used for
            // new connections.
            $channel = Channel::updateOrCreate(
                [
                    'user_id' => $userId,
                    'type' => 'telegram',
                    'page_id' => $botUsername,
                ],
                [
                    'page_name' => $botName,
                    'access_token' => encrypt($botToken),
                    'status' => 'connected',
                    'connected_at' => now(),
                    'business_id' => $businessProfile ? $businessProfile->id : null,
                    'metadata' => [
                        'bot_username' => $botUsername,
                        'bot_name' => $botName,
                        'bot_link' => "https://t.me/{$botUsername}",
                        'webhook_registered_at' => now()->toISOString(),
                    ],
                ]
            );

            $webhookUrl = env('APP_URL') . "/api/telegram/webhook/{$userId}/{$channel->id}";
            $webhookResponse = Http::timeout(10)->post("https://api.telegram.org/bot{$botToken}/setWebhook", [
                'url' => $webhookUrl,
            ]);

            if (!$webhookResponse->successful()) {
                Log::error('Telegram webhook registration failed', [
                    'user_id' => $userId,
                    'channel_id' => $channel->id,
                    'status' => $webhookResponse->status(),
                ]);
                return response()->json(['error' => 'Failed to register webhook'], 500);
            }

            Log::info('Telegram channel connected', [
                'user_id' => $userId,
                'channel_id' => $channel->id,
            ]);

            return response()->json([
                'success' => true,
                'channel' => [
                    'id' => $channel->id,
                    'name' => $botName,
                    'type' => 'telegram',
                    'status' => 'connected',
                    'metadata' => $channel->metadata,
                ],
                'bot_link' => "https://t.me/{$botUsername}",
            ]);

        } catch (\Exception $e) {
            Log::error('Telegram connection error', [
                'user_id' => $userId,
                'exception_type' => get_class($e),
            ]);
            return response()->json(['error' => 'Failed to connect Telegram bot'], 500);
        }
    }

    public function webhook(Request $request, $userId, $channelId = null)
    {
        try {
            $update = $request->all();
            Log::info('Telegram webhook received', ['user_id' => $userId, 'channel_id' => $channelId]);

            if (!isset($update['message'])) {
                return response('OK', 200);
            }

            $message = $update['message'];
            $chatId = $message['chat']['id'];
            $text = trim((string) ($message['text'] ?? $message['caption'] ?? ''));
            $from = $message['from'] ?? [];
            $messageId = $message['message_id'] ?? null;

            if ($channelId !== null) {
                // Exact resolution — the webhook URL itself names the Channel.
                $channel = Channel::where('id', $channelId)
                    ->where('type', 'telegram')
                    ->where('user_id', $userId)
                    ->where('status', 'connected')
                    ->first();

                if (!$channel) {
                    Log::warning('Telegram webhook: channel id in URL not found/connected', [
                        'user_id' => $userId,
                        'channel_id' => $channelId,
                    ]);
                    return response('OK', 200);
                }
            } else {
                // Legacy URL with no channel id (registered before this fix).
                // Only safe to resolve when the user has exactly one connected
                // Telegram bot — otherwise we cannot know which bot this
                // update belongs to, and must not guess.
                $candidates = Channel::where('type', 'telegram')
                    ->where('user_id', $userId)
                    ->where('status', 'connected')
                    ->get();

                if ($candidates->count() === 0) {
                    Log::warning('Telegram webhook (legacy URL): no connected channel for user', ['user_id' => $userId]);
                    return response('OK', 200);
                }

                if ($candidates->count() > 1) {
                    Log::error('Telegram webhook (legacy URL): ambiguous — user has multiple connected Telegram bots and this webhook URL does not identify which one. Run php artisan telegram:resync-webhooks to fix.', [
                        'user_id' => $userId,
                        'candidate_channel_ids' => $candidates->pluck('id'),
                    ]);
                    return response('OK', 200);
                }

                $channel = $candidates->first();
            }

            // Build sender name
            $firstName = $from['first_name'] ?? '';
            $lastName = $from['last_name'] ?? '';
            $username = $from['username'] ?? '';
            $senderName = trim($firstName . ' ' . $lastName) ?: $username ?: 'Unknown';

            $media = $this->downloadInboundMedia($channel, $message);
            $telegramAttachment = $this->telegramAttachmentContext($message, (string) $messageId, $text === '');

            // Create or get conversation
            $conversation = Conversation::firstOrCreate(
                ['channel_id' => $channel->id, 'sender_id' => (string)$chatId],
                [
                    'business_id' => $channel->business_id,
                    'sender_name' => $senderName,
                    'platform' => 'telegram',
                    'status' => 'open',
                    'last_message_at' => now(),
                ]
            );

            $conversation->update(['last_message_at' => now()]);

            // Resolve/link the business-scoped Customer (platform-identity matching)
            try {
                app(\App\Services\CustomerService::class)->attachToConversation($conversation);
            } catch (\Throwable $e) {
                Log::warning('Telegram webhook: customer resolution failed', [
                    'conversation_id' => $conversation->id,
                    'exception_type' => get_class($e),
                ]);
            }

            // Telegram message IDs are unique within a chat; use a composite key.
            $referencedPlatformMessageId = app(\App\Services\ReferencedMessageNormalizer::class)
                ->telegram($message, $chatId);

            // Create message
            $messageModel = Message::create([
                'conversation_id' => $conversation->id,
                'content' => $text,
                'type' => $media['type'] ?? 'text',
                'direction' => 'inbound',
                'platform_message_id' => (string)$messageId,
                'is_ai' => false,
                'status' => 'received',
                'media_url' => $media['url'] ?? null,
                'media_type' => $media['type'] ?? null,
                'mime_type' => $media['mime_type'] ?? null,
                'file_name' => $media['file_name'] ?? null,
                'file_size' => $media['file_size'] ?? null,
                'duration' => $media['duration'] ?? null,
                'media_processing_status' => $media === null
                    ? (($telegramAttachment['status'] ?? null) === 'attachment_without_text' ? 'attachment_without_text' : null)
                    : (($media['storage_status'] ?? null) === 'stored' ? 'pending' : 'failed'),
                'media_processing_reason' => $media !== null && ($media['storage_status'] ?? null) !== 'stored'
                    ? 'Media download or private storage failed.'
                    : null,
                'metadata' => array_filter([
                    'media' => $media,
                    'attachment' => $telegramAttachment,
                    'referenced_platform_message_id' => $referencedPlatformMessageId,
                    'quoted_message_id' => $referencedPlatformMessageId,
                ], fn ($value) => $value !== null),
            ]);

            if ($telegramAttachment !== null) {
                Log::info('Attachment handled', [
                    'message_id' => $messageModel->id,
                    'attachment_type' => $telegramAttachment['type'],
                    'status' => $telegramAttachment['status'],
                ]);
            }

            // Broadcast real-time event
            if ($channel->user_id) {
                broadcast(new \App\Events\MessageReceived($messageModel, $conversation, $channel->user_id));
            }

            // Dispatch AI reply job
            \App\Jobs\ProcessAutoReply::dispatch($messageModel->id);

            return response('OK', 200);

        } catch (\Exception $e) {
            Log::error('Telegram webhook error', [
                'exception_type' => get_class($e),
            ]);
            return response('OK', 200); // Always return 200 to avoid retries
        }
    }

    /** Keep Telegram file identity separate from the customer's text body. */
    private function telegramAttachmentContext(array $message, string $messageId, bool $withoutText): ?array
    {
        foreach (['photo' => 'image', 'voice' => 'audio', 'audio' => 'audio', 'video' => 'video', 'document' => 'document', 'sticker' => 'sticker'] as $key => $type) {
            $payload = $message[$key] ?? null;
            if (!is_array($payload)) {
                continue;
            }
            if ($key === 'photo' && array_is_list($payload)) {
                $payload = end($payload) ?: [];
            }

            return [
                'type' => $type,
                'provider_message_id' => $messageId,
                'provider_attachment_id' => $payload['file_id'] ?? null,
                'status' => $withoutText ? 'attachment_without_text' : 'received_with_text',
            ];
        }

        return null;
    }

    /** Download Telegram media to private storage; never persist provider URLs or tokens. */
    private function downloadInboundMedia(Channel $channel, array $message): ?array
    {
        $mediaType = null;
        $fileId = null;
        $mimeType = null;
        $fileName = null;
        $duration = null;

        if (!empty($message['photo']) && is_array($message['photo'])) {
            $photo = end($message['photo']);
            $mediaType = 'image';
            $fileId = $photo['file_id'] ?? null;
            $mimeType = 'image/jpeg';
        } elseif (!empty($message['voice'])) {
            $mediaType = 'audio';
            $fileId = $message['voice']['file_id'] ?? null;
            $mimeType = $message['voice']['mime_type'] ?? 'audio/ogg';
            $duration = $message['voice']['duration'] ?? null;
        } elseif (!empty($message['audio'])) {
            $mediaType = 'audio';
            $fileId = $message['audio']['file_id'] ?? null;
            $mimeType = $message['audio']['mime_type'] ?? 'audio/mpeg';
            $fileName = $message['audio']['file_name'] ?? null;
            $duration = $message['audio']['duration'] ?? null;
        } elseif (!empty($message['video'])) {
            $mediaType = 'video';
            $fileId = $message['video']['file_id'] ?? null;
            $mimeType = $message['video']['mime_type'] ?? 'video/mp4';
            $fileName = $message['video']['file_name'] ?? null;
            $duration = $message['video']['duration'] ?? null;
        } elseif (!empty($message['document'])) {
            $mediaType = 'document';
            $fileId = $message['document']['file_id'] ?? null;
            $mimeType = $message['document']['mime_type'] ?? 'application/octet-stream';
            $fileName = $message['document']['file_name'] ?? null;
        }

        if ($mediaType === null) {
            return null;
        }
        $failed = ['type' => $mediaType, 'storage_status' => 'failed', 'mime_type' => $mimeType];
        if (!is_string($fileId) || $fileId === '') {
            return $failed;
        }

        try {
            $token = (string) $channel->access_token;
            $apiBase = 'https://api.telegram.org/bot' . $token;
            $fileInfo = Http::timeout(15)->get($apiBase . '/getFile', ['file_id' => $fileId]);
            $filePath = $fileInfo->json('result.file_path');
            $reportedSize = (int) ($fileInfo->json('result.file_size') ?? 0);
            if (!$fileInfo->successful() || !is_string($filePath) || $filePath === '' || $reportedSize > 50 * 1024 * 1024
                || str_contains($filePath, '..') || str_starts_with($filePath, '/')) {
                return $failed;
            }

            $downloadUrl = 'https://api.telegram.org/file/bot' . $token . '/' . ltrim($filePath, '/');
            $download = Http::timeout(30)->withoutRedirecting()->get($downloadUrl);
            if (!$download->successful() || strlen($download->body()) > 50 * 1024 * 1024) {
                return $failed;
            }

            $mime = strtolower(trim(explode(';', (string) $mimeType)[0]));
            $extension = match ($mime) {
                'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp',
                'audio/ogg' => 'ogg', 'audio/mpeg' => 'mp3', 'audio/mp4' => 'm4a',
                'video/mp4' => 'mp4', 'application/pdf' => 'pdf',
                default => strtolower(pathinfo($fileName ?? $filePath, PATHINFO_EXTENSION)) ?: 'bin',
            };
            $storage = app(IncomingMediaStorage::class);
            $path = $storage->store($download->body(), $extension);

            return [
                'type' => $mediaType,
                'url' => $path,
                'storage_disk' => $storage->diskName(),
                'storage_status' => 'stored',
                'mime_type' => $mimeType,
                'file_name' => $fileName,
                'file_size' => strlen($download->body()),
                'duration' => $duration,
            ];
        } catch (\Throwable $exception) {
            Log::warning('Telegram inbound media could not be stored', [
                'channel_id' => $channel->id,
                'media_type' => $mediaType,
                'error_class' => get_class($exception),
            ]);
            return $failed;
        }
    }

    public function setWebhook(Request $request)
    {
        $request->validate([
            'channel_id' => 'required|exists:channels,id',
        ]);

        $channel = Channel::find($request->channel_id);

        if ($channel->type !== 'telegram') {
            return response()->json(['error' => 'Not a Telegram channel'], 400);
        }

        $botToken = $channel->access_token;
        $webhookUrl = env('APP_URL') . "/api/telegram/webhook/{$channel->user_id}/{$channel->id}";

        try {
            $response = Http::timeout(10)->post("https://api.telegram.org/bot{$botToken}/setWebhook", [
                'url' => $webhookUrl,
            ]);

            if ($response->successful()) {
                Log::info('Telegram webhook set successfully', [
                    'channel_id' => $channel->id,
                ]);
                return response()->json(['success' => true]);
            } else {
                Log::error('Failed to set Telegram webhook', [
                    'channel_id' => $channel->id,
                    'status' => $response->status(),
                ]);
                return response()->json(['error' => 'Failed to set webhook'], 500);
            }

        } catch (\Exception $e) {
            Log::error('Telegram webhook set error', [
                'channel_id' => $channel->id,
                'exception_type' => get_class($e),
            ]);
            return response()->json(['error' => 'Failed to set webhook'], 500);
        }
    }

    public function disconnect($id)
    {
        $channel = Channel::where('id', $id)
            ->whereIn('business_id', app(\App\Services\BusinessAccessService::class)->accessibleBusinessIds(auth()->user(), 'write'))
            ->where('type', 'telegram')
            ->first();

        if (!$channel) {
            return response()->json(['error' => 'Channel not found'], 404);
        }

        try {
            $botToken = $channel->access_token;
            
            // Delete webhook
            Http::timeout(10)->post("https://api.telegram.org/bot{$botToken}/deleteWebhook");
            
            Log::info('Telegram webhook deleted', ['channel_id' => $channel->id]);
        } catch (\Exception $e) {
            Log::warning('Failed to delete Telegram webhook', [
                'channel_id' => $channel->id,
                'exception_type' => get_class($e),
            ]);
        }

        $channel->delete();

        Log::info('Telegram channel disconnected', ['channel_id' => $channel->id]);

        return response()->json(['success' => true]);
    }
}
