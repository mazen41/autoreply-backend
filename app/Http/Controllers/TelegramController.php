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
            return response()->json(['error' => 'Bot token is required'], 400);
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
                return response()->json(['error' => 'Invalid bot token'], 400);
            }

            $botInfo = $response->json();
            $botUsername = $botInfo['result']['username'] ?? null;
            $botName = $botInfo['result']['first_name'] ?? 'Telegram Bot';

            // Save channel
            $businessProfile = \App\Models\BusinessProfile::where('user_id', $userId)->first();

            $channel = Channel::updateOrCreate(
                [
                    'user_id' => $userId,
                    'type' => 'telegram',
                    'page_id' => $botUsername, // Store bot username as page_id
                ],
                [
                    'page_name' => $botName,
                    'access_token' => $botToken, // Store bot token as access_token
                    'status' => 'connected',
                    'connected_at' => now(),
                    'business_id' => $businessProfile ? $businessProfile->id : null,
                ]
            );

            // Set webhook for this bot
            $this->setWebhook($botToken);

            Log::info('Telegram channel connected', [
                'user_id' => $userId,
                'bot_username' => $botUsername,
                'channel_id' => $channel->id,
            ]);

            return response()->json([
                'success' => true,
                'channel' => [
                    'id' => $channel->id,
                    'type' => 'telegram',
                    'bot_username' => $botUsername,
                    'bot_name' => $botName,
                    'status' => 'connected',
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Telegram connection error', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
            return response()->json(['error' => 'Failed to connect Telegram bot'], 500);
        }
    }

    public function webhook(Request $request)
    {
        try {
            $update = $request->all();
            Log::info('Telegram webhook received', $update);

            if (!isset($update['message'])) {
                return response('OK', 200);
            }

            $message = $update['message'];
            $chatId = $message['chat']['id'];
            $text = $message['text'] ?? '';
            $fromId = $message['from']['id'];
            $fromName = $message['from']['first_name'] ?? 'Unknown';

            // Find channel by bot username (stored in page_id)
            $botUsername = $update['message']['chat']['username'] ?? null;
            if (!$botUsername) {
                Log::warning('Telegram webhook: no chat username in message');
                return response('OK', 200);
            }

            $channel = Channel::where('type', 'telegram')
                ->where('page_id', $botUsername)
                ->where('status', 'connected')
                ->first();

            if (!$channel) {
                Log::warning('Telegram webhook: no channel found for bot', ['bot_username' => $botUsername]);
                return response('OK', 200);
            }

            // Create or get conversation
            $conversation = Conversation::firstOrCreate(
                ['channel_id' => $channel->id, 'sender_id' => $chatId],
                [
                    'business_id' => $channel->business_id,
                    'sender_name' => $fromName,
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

        $botToken = $channel->access_token;
        $webhookUrl = env('APP_URL') . '/api/telegram/webhook';

        try {
            $response = Http::post("https://api.telegram.org/bot{$botToken}/setWebhook", [
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
}