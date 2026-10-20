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

        $business = $conversation->business;
        if ($business && app(UsageMeter::class)->blockMessageIfOverLimit($business, $message)) {
            Log::notice('ChannelMessageService: outbound message blocked by business plan', [
                'business_id' => $business->id,
                'conversation_id' => $conversation->id,
                'message_id' => $message->id,
            ]);
            return false;
        }

        try {
            $success = false;

            switch ($channel->type) {
                case 'facebook':
                    $success = $this->sendFacebookReply($channel, $senderId, $content, $images, $message);
                    break;
                case 'instagram':
                    $success = $this->sendInstagramReply($channel, $senderId, $content, $images, $message);
                    break;
                case 'gmail':
                    $success = $this->sendGmailReply($channel, $conversation, $content, $message);
                    break;
                case 'whatsapp':
                    $success = $this->sendWhatsAppReply($channel, $conversation, $message, $senderId, $content, $images);
                    break;
                case 'telegram':
                    $success = $this->sendTelegramReply($channel, $senderId, $content);
                    break;
                case 'tiktok':
                    $message->update(['error_details' => 'unsupported_channel_type']);
                    $success = false;
                    break;
                default:
                    Log::warning("ChannelMessageService: unsupported channel type", [
                        'channel_type' => $channel->type,
                        'channel_id' => $channel->id,
                    ]);
                    $message->update(['error_details' => 'unsupported_channel_type']);
                    $success = false;
                    break;
            }

            if ($success) {
                $message->update([
                    'status' => 'sent',
                    'send_status' => 'sent',
                    'delivery_status' => 'sent',
                    'error_details' => null,
                ]);
                $channel->update(['last_error_at' => null, 'last_error_code' => null]);
                Log::info('ChannelMessageService: message sent successfully', [
                    'platform' => $channel->type,
                    'message_id' => $message->id,
                    'conversation_id' => $conversation->id,
                    'correlation_id' => $message->correlation_id,
                    'provider_message_id_present' => !empty($message->fresh()->platform_message_id),
                ]);
            } else {
                $fresh = $message->fresh();
                $blocked = $fresh?->send_status === 'blocked' || $fresh?->status === 'blocked';
                $message->update([
                    'status' => $blocked ? 'blocked' : 'failed',
                    'send_status' => $blocked ? 'blocked' : 'failed',
                    'delivery_status' => $blocked ? $fresh?->delivery_status : 'failed',
                    'error_details' => $fresh?->error_details ?: ($blocked ? 'send_blocked_by_policy' : 'provider_rejected'),
                ]);
                $channel->update(['last_error_at' => now(), 'last_error_code' => $blocked ? 'send_blocked_by_policy' : 'provider_rejected']);
                Log::error('ChannelMessageService: message send failed', [
                    'platform' => $channel->type,
                    'message_id' => $message->id,
                    'correlation_id' => $message->correlation_id,
                    'delivery_status' => $blocked ? 'blocked' : 'failed',
                ]);
            }

            return $success;

        } catch (\Exception $e) {
            $message->update([
                'status' => 'failed',
                'send_status' => 'failed',
                'delivery_status' => 'failed',
                'error_details' => 'provider_exception:' . class_basename($e),
            ]);
            $channel->update(['last_error_at' => now(), 'last_error_code' => class_basename($e)]);
            Log::error('ChannelMessageService: send message exception', [
                'exception_type' => get_class($e),
                'platform' => $channel->type,
                'message_id' => $message->id,
                'correlation_id' => $message->correlation_id,
            ]);
            return false;
        }
    }

    private function sendFacebookReply(Channel $channel, string $recipientId, string $message, array $images = [], ?Message $messageRecord = null): bool
    {
        $result = app(MetaMessengerService::class)
            ->sendTextWithResult($channel, $recipientId, $message, $images);
        if ($messageRecord) {
            $metadata = $messageRecord->metadata ?? [];
            $metadata['provider_message_ids'] = $result['message_ids'] ?? [];
            if (!empty($result['message_id'])) {
                $metadata['platform_message_id'] = $result['message_id'];
            }
            $messageRecord->update([
                'metadata' => $metadata,
                'platform_message_id' => $result['message_id'] ?? null,
                'platform_message_hash' => !empty($result['message_id']) ? hash('sha256', (string) $result['message_id']) : null,
                'error_details' => $result['error'] ?? null,
            ]);
        }

        return (bool) $result['success'];
    }

    private function sendInstagramReply(Channel $channel, string $recipientId, string $message, array $images = [], ?Message $messageRecord = null): bool
    {
        // Instagram uses the same API as Facebook with the page access token
        return $this->sendFacebookReply($channel, $recipientId, $message, $images, $messageRecord);
    }

    private function sendGmailReply(Channel $channel, Conversation $conversation, string $body, ?Message $messageRecord = null): bool
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

            $sent = $gmail->users_messages->send('me', $messageObj);
            $providerMessageId = $sent->getId();
            if ($messageRecord && $providerMessageId) {
                $metadata = is_array($messageRecord->metadata) ? $messageRecord->metadata : [];
                $metadata['platform_message_id'] = (string) $providerMessageId;
                $metadata['provider_message_ids'] = [(string) $providerMessageId];
                $messageRecord->update([
                    'gmail_message_id' => (string) $providerMessageId,
                    'platform_message_id' => (string) $providerMessageId,
                    'platform_message_hash' => hash('sha256', (string) $providerMessageId),
                    'metadata' => $metadata,
                ]);
            }
            return true;

        } catch (\Exception $e) {
            Log::error('ChannelMessageService: Gmail send exception', ['exception_type' => get_class($e)]);
            return false;
        }
    }

    private function sendWhatsAppReply(Channel $channel, Conversation $conversation, Message $messageRecord, string $recipientId, string $message, array $images = []): bool
    {
        try {
            if (($channel->metadata['whatsapp_provider'] ?? null) === 'cloud') {
                if (!$this->isWithinWhatsAppCustomerCareWindow($conversation)) {
                    $messageRecord->update([
                        'status' => 'blocked', 'send_status' => 'blocked',
                        'error_details' => 'WhatsApp Cloud customer care window expired.',
                    ]);
                    Log::notice('WhatsApp Cloud free-form message blocked outside customer care window', [
                        'channel_id' => $channel->id,
                        'conversation_id' => $conversation->id,
                    ]);
                    return false;
                }

                $cloud = app(WhatsAppCloudApiService::class);
                foreach ($images as $imageUrl) {
                    $imageResult = $cloud->sendImage($channel, $recipientId, $imageUrl);
                    if (!$imageResult['success']) {
                        $messageRecord->update(['error_details' => 'WhatsApp Cloud image send failed: ' . ($imageResult['reason'] ?? 'provider_rejected')]);
                        return false;
                    }
                }
                $result = $cloud->sendText($channel, $recipientId, $message);
                if ($result['success'] && !empty($result['message_id'])) {
                    $messageRecord->update([
                        'whatsapp_message_id' => $result['message_id'],
                        'platform_message_id' => $result['message_id'],
                        'platform_message_hash' => hash('sha256', (string) $result['message_id']),
                    ]);
                }
                if (!$result['success']) {
                    $messageRecord->update(['error_details' => 'WhatsApp Cloud text send failed: ' . ($result['reason'] ?? 'provider_rejected')]);
                }
                return $result['success'];
            }

            if (($channel->metadata['unofficial_risk_accepted'] ?? false) !== true) {
                Log::warning('Unofficial WhatsApp send blocked: risk acknowledgment is missing', [
                    'channel_id' => $channel->id,
                    'conversation_id' => $conversation->id,
                ]);
                $messageRecord->update([
                    'status' => 'blocked', 'send_status' => 'blocked',
                    'error_details' => 'Unofficial WhatsApp risk acknowledgment is missing.',
                ]);
                return false;
            }

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
                $messageRecord->update([
                    'whatsapp_message_id' => $response['key']['id'],
                    'platform_message_id' => $response['key']['id'],
                    'platform_message_hash' => hash('sha256', (string) $response['key']['id']),
                    'whatsapp_remote_jid' => $response['key']['remoteJid'] ?? $recipientId,
                    'whatsapp_from_me' => true,
                ]);

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

            $messageRecord->update(['error_details' => 'WhatsApp provider did not return a message ID.']);
            return false;

        } catch (\Exception $e) {
            Log::error('ChannelMessageService: WhatsApp send exception', ['exception_type' => get_class($e)]);
            $messageRecord->update(['error_details' => substr($e->getMessage(), 0, 1000)]);
            return false;
        }
    }

    private function isWithinWhatsAppCustomerCareWindow(Conversation $conversation): bool
    {
        $lastInbound = $conversation->messages()
            ->where('direction', 'inbound')
            ->latest('created_at')
            ->first();

        return $lastInbound !== null && $lastInbound->created_at->gt(now()->subHours(24));
    }

    private function sendTelegramReply(Channel $channel, string $chatId, string $message): bool
    {
        try {
            $botToken = $channel->access_token;
            $telegramService = new TelegramService();

            $success = $telegramService->sendMessage($botToken, $chatId, $message);

            if (!$success) {
                Log::error('ChannelMessageService: Telegram send failed', [
                    'chat_id' => $chatId,
                ]);
            }

            return $success;

        } catch (\Exception $e) {
            Log::error('ChannelMessageService: Telegram send exception', ['exception_type' => get_class($e)]);
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
            Log::error('ChannelMessageService: TikTok send exception', ['exception_type' => get_class($e)]);
            return false;
        }
    }
}
