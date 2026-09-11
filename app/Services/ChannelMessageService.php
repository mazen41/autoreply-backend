<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ChannelMessageService
{
    /**
     * Send a message through the appropriate channel provider
     *
     * This service extracts and reuses the provider-specific sending logic
     * from ProcessAutoReply to provide a common delivery path for both
     * AI replies and workflow actions.
     *
     * @param Channel $channel The channel to send through
     * @param Conversation $conversation The conversation context
     * @param Message $message The message record to send
     * @param array $images Optional image URLs for media messages
     * @return bool True if send succeeded, false otherwise
     */
    public function sendMessage(Channel $channel, Conversation $conversation, Message $message, array $images = []): bool
    {
        $senderId = $conversation->sender_id;
        $content = $message->content;

        try {
            $success = false;

            switch ($channel->type) {
                case 'facebook':
                    $success = $this->sendFacebookReply($channel, $senderId, $content, $images);
                    break;
                case 'instagram':
                    $success = $this->sendInstagramReply($channel, $senderId, $content, $images);
                    break;
                case 'gmail':
                    $success = $this->sendGmailReply($channel, $conversation, $content);
                    break;
                case 'whatsapp':
                    $success = $this->sendWhatsAppReply($channel, $senderId, $content, $images);
                    break;
                case 'telegram':
                    $success = $this->sendTelegramReply($channel, $senderId, $content);
                    break;
                case 'tiktok':
                    $success = $this->sendTikTokReply($channel, $senderId, $content);
                    break;
                default:
                    Log::warning("ChannelMessageService: unsupported channel type", [
                        'channel_type' => $channel->type,
                        'channel_id' => $channel->id,
                    ]);
                    return false;
            }

            if ($success) {
                $message->update(['send_status' => 'sent']);
                Log::info('ChannelMessageService: message sent successfully', [
                    'platform' => $channel->type,
                    'message_id' => $message->id,
                    'conversation_id' => $conversation->id,
                ]);
            } else {
                $message->update(['send_status' => 'failed']);
                Log::error('ChannelMessageService: message send failed', [
                    'platform' => $channel->type,
                    'message_id' => $message->id,
                ]);
            }

            return $success;

        } catch (\Exception $e) {
            $message->update(['send_status' => 'failed']);
            Log::error('ChannelMessageService: send message exception', [
                'error' => $e->getMessage(),
                'platform' => $channel->type,
                'message_id' => $message->id,
            ]);
            return false;
        }
    }

    private function sendFacebookReply(Channel $channel, string $recipientId, string $message, array $images = []): bool
    {
        $accessToken = $channel->access_token;
        $baseUrl = "https://graph.facebook.com/v19.0/me/messages?access_token={$accessToken}";

        // Send images first
        foreach ($images as $imageUrl) {
            Http::timeout(10)->post($baseUrl, [
                'recipient' => ['id' => $recipientId],
                'message' => [
                    'attachment' => [
                        'type' => 'image',
                        'payload' => [
                            'url' => $imageUrl,
                            'is_reusable' => true
                        ]
                    ]
                ],
            ]);
        }

        // Send text message
        $response = Http::timeout(10)
            ->post($baseUrl, [
                'recipient' => ['id' => $recipientId],
                'message' => ['text' => $message],
            ]);

        if (!$response->successful()) {
            Log::error('ChannelMessageService: Facebook send failed', [
                'status' => $response->status(),
                'body' => $response->json(),
                'recipient' => $recipientId,
            ]);
        }

        return $response->successful();
    }

    private function sendInstagramReply(Channel $channel, string $recipientId, string $message, array $images = []): bool
    {
        // Instagram uses the same API as Facebook with the page access token
        return $this->sendFacebookReply($channel, $recipientId, $message, $images);
    }

    private function sendGmailReply(Channel $channel, Conversation $conversation, string $body): bool
    {
        $gmailCtrl = new \App\Http\Controllers\GmailController();
        $client = $gmailCtrl->getAuthenticatedClient($channel);

        if (!$client) {
            Log::error('ChannelMessageService: could not get Gmail client', ['channel_id' => $channel->id]);
            return false;
        }

        try {
            $gmail = new \Google\Service\Gmail($client);
            $to = $conversation->sender_email ?? 'unknown';
            $subject = $conversation->subject ?? 'Re: Your message';
            $threadId = $conversation->sender_id; // sender_id stores threadId for Gmail

            // Get the original message Gmail-header Message-ID for threading
            $originalMessage = Message::where('conversation_id', $conversation->id)
                ->where('direction', 'inbound')
                ->whereNotNull('gmail_message_id')
                ->orderBy('created_at', 'asc')
                ->first();

            $inReplyToId = $originalMessage?->gmail_message_id ?? '';

            $raw = "To: {$to}\r\n";
            $raw .= "Subject: Re: {$subject}\r\n";
            if ($inReplyToId) {
                $raw .= "In-Reply-To: {$inReplyToId}\r\n";
                $raw .= "References: {$inReplyToId}\r\n";
            }
            $raw .= "Content-Type: text/plain; charset=utf-8\r\n\r\n";
            $raw .= $body;

            $encoded = rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');

            $messageObj = new \Google\Service\Gmail\Message();
            $messageObj->setRaw($encoded);
            if ($threadId) {
                $messageObj->setThreadId($threadId);
            }

            $gmail->users_messages->send('me', $messageObj);
            return true;

        } catch (\Exception $e) {
            Log::error('ChannelMessageService: Gmail send exception', ['error' => $e->getMessage()]);
            return false;
        }
    }

    private function sendWhatsAppReply(Channel $channel, string $recipientId, string $message, array $images = []): bool
    {
        try {
            $whatsappService = new EvolutionApiService();
            $instanceName = $channel->page_id; // We store instance_name in page_id for WhatsApp

            // Send images first
            foreach ($images as $imageUrl) {
                $whatsappService->sendMediaMessage(
                    $instanceName,
                    $recipientId,
                    $imageUrl,
                    '',
                    'image'
                );
            }

            // Send the actual text message
            $response = $whatsappService->sendTextMessage($instanceName, $recipientId, $message);

            if (isset($response['key']['id'])) {
                $instance = \App\Models\WhatsAppInstance::where('instance_name', $instanceName)->first();

                if ($instance) {
                    \App\Models\WhatsAppMessage::create([
                        'whatsapp_instance_id' => $instance->id,
                        'user_id' => $channel->user_id,
                        'message_id' => $response['key']['id'] ?? null,
                        'remote_message_id' => $response['key']['id'] ?? null,
                        'direction' => 'outgoing',
                        'from_phone' => null,
                        'from_name' => null,
                        'to_phone' => $recipientId,
                        'body' => $message,
                        'message_type' => 'text',
                        'media' => null,
                        'metadata' => ['evolution_message_id' => $response['key']['id'] ?? null],
                        'status' => 'sent',
                        'sent_at' => now(),
                    ]);
                } else {
                    Log::warning('ChannelMessageService: WhatsAppInstance not found for legacy message record', [
                        'instance_name' => $instanceName,
                        'channel_id' => $channel->id,
                    ]);
                }

                return true;
            }

            return false;

        } catch (\Exception $e) {
            Log::error('ChannelMessageService: WhatsApp send exception', ['error' => $e->getMessage()]);
            return false;
        }
    }

    private function sendTelegramReply(Channel $channel, string $chatId, string $message): bool
    {
        try {
            $botToken = decrypt($channel->access_token);
            $telegramService = new TelegramService();

            $success = $telegramService->sendMessage($botToken, $chatId, $message);

            if (!$success) {
                Log::error('ChannelMessageService: Telegram send failed', [
                    'chat_id' => $chatId,
                ]);
            }

            return $success;

        } catch (\Exception $e) {
            Log::error('ChannelMessageService: Telegram send exception', ['error' => $e->getMessage()]);
            return false;
        }
    }

    private function sendTikTokReply(Channel $channel, string $userId, string $message): bool
    {
        try {
            // TikTok API requires specific OAuth scopes for commenting
            // This is a placeholder implementation - actual TikTok commenting requires
            // additional API setup and permissions
            $accessToken = $channel->access_token;
            $openId = $channel->metadata['open_id'] ?? null;

            if (!$openId) {
                Log::error('ChannelMessageService: TikTok reply failed - no open_id', ['channel_id' => $channel->id]);
                return false;
            }

            // TikTok API for sending comments is restricted and requires special permissions
            // For now, we'll log this as a limitation
            Log::warning('ChannelMessageService: TikTok direct replies are not supported via public API', [
                'channel_id' => $channel->id,
                'user_id' => $userId,
            ]);

            return false;

        } catch (\Exception $e) {
            Log::error('ChannelMessageService: TikTok send exception', ['error' => $e->getMessage()]);
            return false;
        }
    }
}
