<?php

namespace App\Http\Controllers;

use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramController extends Controller
{
    public function connect(Request $request)
    {
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
                    'body' => $response->json(),
                ]);
                return response()->json(['error' => 'Invalid bot token. Please check and try again.'], 422);
            }

            $botInfo = $response->json();
            $botUsername = $botInfo['result']['username'] ?? null;
            $botName = $botInfo['result']['first_name'] ?? 'Telegram Bot';

            // Set webhook for this bot with user_id in URL
            $webhookUrl = env('APP_URL') . "/api/telegram/webhook/{$userId}";
            $webhookResponse = Http::timeout(10)->post("https://api.telegram.org/bot{$botToken}/setWebhook", [
                'url' => $webhookUrl,
            ]);

            if (!$webhookResponse->successful()) {
                Log::error('Telegram webhook registration failed', [
                    'user_id' => $userId,
                    'bot_username' => $botUsername,
                    'response' => $webhookResponse->json(),
                ]);
                return response()->json(['error' => 'Failed to register webhook'], 500);
            }

            // Save channel with encrypted token and metadata
            $businessProfile = \App\Models\BusinessProfile::where('user_id', $userId)->first();

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

            Log::info('Telegram channel connected', [
                'user_id' => $userId,
                'bot_username' => $botUsername,
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
                'error' => $e->getMessage(),
            ]);
            return response()->json(['error' => 'Failed to connect Telegram bot'], 500);
        }
    }

    public function webhook(Request $request, $userId)
    {
        try {
            $update = $request->all();
            Log::info('Telegram webhook received', ['user_id' => $userId]);

            if (!isset($update['message'])) {
                return response('OK', 200);
            }

            $message = $update['message'];
            $chatId = $message['chat']['id'];
            $text = $message['text'] ?? '[Media message]';
            $from = $message['from'] ?? [];
            $messageId = $message['message_id'] ?? null;

            // Find channel by user_id and type
            $channel = Channel::where('type', 'telegram')
                ->where('user_id', $userId)
                ->where('status', 'connected')
                ->first();

            if (!$channel) {
                Log::warning('Telegram webhook: no channel found for user', ['user_id' => $userId]);
                return response('OK', 200);
            }

            // Build sender name
            $firstName = $from['first_name'] ?? '';
            $lastName = $from['last_name'] ?? '';
            $username = $from['username'] ?? '';
            $senderName = trim($firstName . ' ' . $lastName) ?: $username ?: 'Unknown';

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

            // Create message
            $messageModel = Message::create([
                'conversation_id' => $conversation->id,
                'content' => $text,
                'direction' => 'inbound',
                'platform_message_id' => (string)$messageId,
                'is_ai' => false,
                'status' => 'received',
            ]);

            // Broadcast real-time event
            if ($channel->user_id) {
                broadcast(new \App\Events\MessageReceived($messageModel, $conversation, $channel->user_id));
            }

            // Dispatch AI reply job
            \App\Jobs\ProcessAutoReply::dispatch($messageModel->id);

            return response('OK', 200);

        } catch (\Exception $e) {
            Log::error('Telegram webhook error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response('OK', 200); // Always return 200 to avoid retries
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

        $botToken = decrypt($channel->access_token);
        $webhookUrl = env('APP_URL') . "/api/telegram/webhook/{$channel->user_id}";

        try {
            $response = Http::timeout(10)->post("https://api.telegram.org/bot{$botToken}/setWebhook", [
                'url' => $webhookUrl,
            ]);

            if ($response->successful()) {
                Log::info('Telegram webhook set successfully', [
                    'channel_id' => $channel->id,
                    'webhook_url' => $webhookUrl,
                ]);
                return response()->json(['success' => true]);
            } else {
                Log::error('Failed to set Telegram webhook', [
                    'channel_id' => $channel->id,
                    'response' => $response->json(),
                ]);
                return response()->json(['error' => 'Failed to set webhook'], 500);
            }

        } catch (\Exception $e) {
            Log::error('Telegram webhook set error', [
                'channel_id' => $channel->id,
                'error' => $e->getMessage(),
            ]);
            return response()->json(['error' => 'Failed to set webhook'], 500);
        }
    }

    public function disconnect($id)
    {
        $channel = Channel::where('id', $id)
            ->where('user_id', auth()->id())
            ->where('type', 'telegram')
            ->first();

        if (!$channel) {
            return response()->json(['error' => 'Channel not found'], 404);
        }

        try {
            $botToken = decrypt($channel->access_token);
            
            // Delete webhook
            Http::timeout(10)->post("https://api.telegram.org/bot{$botToken}/deleteWebhook");
            
            Log::info('Telegram webhook deleted', ['channel_id' => $channel->id]);
        } catch (\Exception $e) {
            Log::warning('Failed to delete Telegram webhook', [
                'channel_id' => $channel->id,
                'error' => $e->getMessage(),
            ]);
        }

        $channel->delete();

        Log::info('Telegram channel disconnected', ['channel_id' => $channel->id]);

        return response()->json(['success' => true]);
    }
}