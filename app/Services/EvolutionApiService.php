<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\WhatsAppInstance;
use App\Models\WhatsAppMessage;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use Exception;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class EvolutionApiService
{
    protected string $baseUrl;
    protected string $apiKey;
    protected int $timeout;
    protected int $maxRetries;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('services.evolution.base_url', 'http://localhost:8080'), '/');
        $this->apiKey = config('services.evolution.api_key', '');
        $this->timeout = config('services.evolution.timeout', 30);
        $this->maxRetries = config('services.evolution.max_retries', 3);
    }

    /**
     * Create a new Evolution instance for a user
     */
    public function createInstance(string $instanceName, array $options = []): array
    {
        $url = "{$this->baseUrl}/instance/create";
        
        $payload = array_merge([
            'instanceName' => $instanceName,
            'qrcode' => true,
            'integration' => 'WHATSAPP-BAILEYS',
                'webhook' => [
                    'url' => route('whatsapp.webhook'),
                    'webhook_by_events' => true,
                    'base64' => true,
                    'events' => [
                        'MESSAGES_UPSERT',
                        'MESSAGES_UPDATE',
                        'MESSAGES_REACTION',
                        'SEND_MESSAGE',
                        'CONNECTION_UPDATE',
                        'STATUS_INSTANCE',
                ],
            ],
        ], $options);

        return $this->makeRequest('POST', $url, $payload);
    }

    /**
     * Fetch instance information
     */
    public function fetchInstance(string $instanceName): array
    {
        $url = "{$this->baseUrl}/instance/fetchInstances?instanceName={$instanceName}";
        return $this->makeRequest('GET', $url);
    }

    /**
     * Get connection state of an instance
     */
    public function getConnectionState(string $instanceName): array
    {
        $url = "{$this->baseUrl}/instance/connectionState/{$instanceName}";
        return $this->makeRequest('GET', $url);
    }

    /**
     * Get QR code for an instance
     */
    public function getQrCode(string $instanceName): array
    {
        $url = "{$this->baseUrl}/instance/connect/{$instanceName}";
        return $this->makeRequest('GET', $url);
    }

    /**
     * Logout and disconnect an instance
     */
    public function logoutInstance(string $instanceName): array
    {
        $url = "{$this->baseUrl}/instance/logout/{$instanceName}";
        return $this->makeRequest('DELETE', $url);
    }

    /**
     * Delete an instance
     */
    public function deleteInstance(string $instanceName): array
    {
        $url = "{$this->baseUrl}/instance/delete/{$instanceName}";
        return $this->makeRequest('DELETE', $url);
    }

    /**
     * Send a text message
     */
    public function sendTextMessage(string $instanceName, string $number, string $text): array
    {
        $url = "{$this->baseUrl}/message/sendText/{$instanceName}";
        
        // Strip @s.whatsapp.net suffix if present
        $cleanNumber = str_replace('@s.whatsapp.net', '', $number);
        
        $payload = [
            'number' => $cleanNumber,
            'text' => $text,
            'options' => [
                'delay' => 1200,
                'presence' => 'composing',
                'linkPreview' => false,
            ],
        ];

        return $this->makeRequest('POST', $url, $payload);
    }

    /**
     * Send a media message
     */
    public function sendMediaMessage(string $instanceName, string $number, string $mediaUrl, string $caption = '', string $mediaType = 'image', ?string $mimeType = null, ?string $fileName = null): array
    {
        $url = "{$this->baseUrl}/message/sendMedia/{$instanceName}";

        $cleanNumber = str_replace('@s.whatsapp.net', '', $number);

        // Evolution API v2's documented /message/sendMedia schema is FLAT —
        // { number, mediatype, mimetype, caption, media, fileName, delay, ... }.
        // Evolution v2 runs on NestJS with strict DTO validation, which rejects
        // unrecognized properties with 400 Bad Request. The old code sent an
        // extra nested `mediaMessage` object (a v1-era format) alongside the
        // flat fields, which is exactly what was causing every media send to
        // fail with HTTP 400. Keep this payload flat and match the docs exactly.
        $payload = [
            'number' => $cleanNumber,
            'mediatype' => $mediaType,
            'media' => $mediaUrl,
            'caption' => $caption,
            'delay' => 1200,
        ];

        if ($mimeType) {
            $payload['mimetype'] = $mimeType;
        }

        if ($fileName) {
            $payload['fileName'] = $fileName;
        }

        return $this->makeRequest('POST', $url, $payload);
    }

    /**
     * Send a WhatsApp push-to-talk audio message.
     */
    public function sendWhatsAppAudio(string $instanceName, string $number, string $audioUrl): array
    {
        $url = "{$this->baseUrl}/message/sendWhatsAppAudio/{$instanceName}";
        $cleanNumber = str_replace('@s.whatsapp.net', '', $number);

        return $this->makeRequest('POST', $url, [
            'number' => $cleanNumber,
            'audio' => $audioUrl,
        ]);
    }

    /**
     * Ask Evolution to decrypt a received WhatsApp media message and return it as base64.
     */
    public function getBase64FromMediaMessage(string $instanceName, array $messageKey, bool $convertToMp4 = false): array
    {
        $url = "{$this->baseUrl}/chat/getBase64FromMediaMessage/{$instanceName}";

        return $this->makeRequest('POST', $url, [
            'message' => [
                'key' => $messageKey,
            ],
            'convertToMp4' => $convertToMp4,
        ]);
    }

    /**
     * Send a reaction to a message
     */
    public function sendReaction(string $instanceName, string $remoteJid, string $messageId, string $emoji): array
    {
        $url = "{$this->baseUrl}/message/sendReaction/{$instanceName}";
        
        $payload = [
            'key' => [
                'remoteJid' => $remoteJid,
                'fromMe' => false,
                'id' => $messageId,
            ],
            'reaction' => $emoji,
        ];

        return $this->makeRequest('POST', $url, $payload);
    }

    /**
     * Check if instance exists
     */
    public function instanceExists(string $instanceName): bool
    {
        try {
            $response = $this->fetchInstance($instanceName);
            return isset($response[0]) && $response[0]['instance']['instanceName'] === $instanceName;
        } catch (Exception $e) {
            Log::error("Failed to check instance existence: {$e->getMessage()}");
            return false;
        }
    }

    /**
     * Register webhook for an instance
     */
    public function registerWebhook(string $instanceName, string $webhookUrl, array $events = []): array
    {
        $url = "{$this->baseUrl}/instance/setWebhook/{$instanceName}";
        
        $defaultEvents = [
            'MESSAGES_UPSERT',
            'MESSAGES_UPDATE',
            'MESSAGES_REACTION',
            'SEND_MESSAGE',
            'CONNECTION_UPDATE',
            'STATUS_INSTANCE',
        ];

        $payload = [
            'url' => $webhookUrl,
            'webhook_by_events' => true,
            'base64' => true,
            'events' => $events ?: $defaultEvents,
        ];

        return $this->makeRequest('POST', $url, $payload);
    }

    /**
     * Get instance profile picture
     */
    public function getProfilePicture(string $instanceName, string $number): array
    {
        $url = "{$this->baseUrl}/instance/profilePicture/{$instanceName}";
        return $this->makeRequest('GET', $url, ['target' => $number]);
    }

    /**
     * Make HTTP request with retry logic
     */
    protected function makeRequest(string $method, string $url, array $data = null): array
    {
        $attempt = 0;
        $lastException = null;

        while ($attempt < $this->maxRetries) {
            try {
                $response = Http::timeout($this->timeout)
                    ->withHeaders([
                        'apikey' => $this->apiKey,
                        'Content-Type' => 'application/json',
                    ]);

                if ($method === 'GET') {
                    $response = $response->get($url, $data);
                } elseif ($method === 'POST') {
                    $response = $response->post($url, $data);
                } elseif ($method === 'DELETE') {
                    $response = $response->delete($url, $data);
                }

                if ($response->successful()) {
                    return $response->json();
                }

                // Log the actual response body so we can see WHY Evolution
                // rejected the request (validation errors, bad media URL, etc.)
                // — previously only the status code was kept, which hid the
                // real reason for every 400/422 failure.
                Log::error('Evolution API returned non-success response', [
                    'url' => $url,
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'sent_payload' => $data,
                ]);

                throw new Exception("HTTP request failed with status: {$response->status()} — body: {$response->body()}");
            } catch (Exception $e) {
                $attempt++;
                $lastException = $e;

                if ($attempt < $this->maxRetries) {
                    $delay = min(pow(2, $attempt), 10); // Exponential backoff, max 10 seconds
                    sleep($delay);
                    Log::warning("Retrying request (attempt {$attempt}/{$this->maxRetries}) after {$delay}s delay: {$url}");
                }
            }
        }

        Log::error("Failed to complete request after {$this->maxRetries} attempts: {$url}");
        throw $lastException ?? new Exception('Request failed after maximum retries');
    }

    /**
     * Process webhook event
     */
    public function processWebhookEvent(array $event): void
    {
        try {
            $rawEventType = $event['event'] ?? null;

            // Evolution API is inconsistent about event name casing/format across
            // versions — docs show "CONNECTION_UPDATE" but live payloads often send
            // "connection.update" (lowercase, dot-separated). Normalize to the
            // uppercase-underscore form our switch below expects, so we match
            // regardless of which style Evolution sends.
            $eventType = $rawEventType ? strtoupper(str_replace('.', '_', $rawEventType)) : null;

            Log::info('Evolution webhook event normalized', [
                'raw_event' => $rawEventType,
                'normalized_event' => $eventType,
                'instance' => $event['instance'] ?? null,
            ]);

            switch ($eventType) {
                case 'MESSAGES_UPSERT':
                    $this->handleMessageUpsert($event);
                    break;
                case 'MESSAGES_UPDATE':
                    $this->handleMessageUpdate($event);
                    break;
                case 'MESSAGES_REACTION':
                    $this->handleMessageReaction($event);
                    break;
                case 'CONNECTION_UPDATE':
                    $this->handleConnectionUpdate($event);
                    break;
                case 'STATUS_INSTANCE':
                    $this->handleStatusInstance($event);
                    break;
                default:
                    Log::info("Unhandled webhook event type: {$eventType} (raw: {$rawEventType})");
            }
        } catch (Exception $e) {
            Log::error("Failed to process webhook event: {$e->getMessage()}", ['event' => $event]);
        }
    }

    /**
     * Handle incoming message
     */
    protected function handleMessageUpsert(array $event): void
    {
        $instanceName = $event['instance'] ?? null;
        $message = $event['data']['key'] ?? [];
        $messageContent = $event['data']['message'] ?? [];

        if (!$instanceName) {
            Log::error('Message event missing instance name');
            return;
        }

        $instance = WhatsAppInstance::where('instance_name', $instanceName)->first();
        if (!$instance) {
            Log::error("Instance not found for webhook: {$instanceName}");
            return;
        }

        $fromPhone = $message['remoteJid'] ?? null;
        
        // Try to get pushName from different locations in the webhook structure
        $fromName = $message['pushName'] ?? null;
        if (empty($fromName) || $fromName === '.') {
            $fromName = $event['data']['pushName'] ?? null;
        }
        
        // Log the name we received for debugging
        Log::info("WhatsApp webhook received contact info", [
            'from_phone' => $fromPhone,
            'push_name' => $fromName,
            'instance' => $instanceName,
        ]);
        
        // If pushName is empty or just a dot, use the phone number instead
        if (empty($fromName) || $fromName === '.') {
            $fromName = $this->formatPhoneNumber($fromPhone);
            Log::info("Using phone number as name since pushName is empty", [
                'from_phone' => $fromPhone,
                'formatted_name' => $fromName,
            ]);
        }
        
        // Skip messages sent FROM this WhatsApp instance (our own outgoing messages)
        // Evolution sends MESSAGES_UPSERT for all messages including ones we send,
        // marked with fromMe=true in the key.
        $fromMe = $message['fromMe'] ?? false;

        $body = $messageContent['conversation'] ?? $messageContent['extendedTextMessage']['text'] ?? null;
        $messageType = $this->detectMessageType($messageContent);
        $media = $this->extractAndStoreMedia($messageContent, $event, $instanceName, $message);

        if ($this->isReactionMessage($messageContent)) {
            $this->handleMessageReaction($event);
            return;
        }

        // Save to WhatsApp messages table (legacy)
        $whatsappMessage = WhatsAppMessage::create([
            'whatsapp_instance_id' => $instance->id,
            'user_id' => $instance->user_id,
            'message_id' => $message['id'] ?? null,
            'remote_message_id' => $message['id'] ?? null,
            'direction' => $fromMe ? 'outgoing' : 'incoming',
            'from_phone' => $fromPhone,
            'from_name' => $fromName,
            'to_phone' => $instance->phone_number,
            'body' => $body,
            'message_type' => $messageType,
            'media' => $media,
            'metadata' => [
                'event' => $event,
                'message_key' => $message,
                'pushName' => $fromName,
            ],
            'status' => 'pending',
            'sent_at' => now(),
        ]);

        // Also save to unified inbox system
        $this->saveToUnifiedInbox($instance, $fromPhone, $fromName, $body, $messageType, $media, $message, $fromMe);

        Log::info("Message saved from {$fromPhone} for instance {$instanceName} (fromMe: " . ($fromMe ? 'true' : 'false') . ")");
    }

    /**
     * Save WhatsApp message to unified inbox (Conversation/Message)
     */
    protected function saveToUnifiedInbox(WhatsAppInstance $instance, ?string $fromPhone, ?string $fromName, ?string $body, string $messageType, ?array $media = null, array $messageKey = [], bool $fromMe = false): void
    {
        try {
            // Find the WhatsApp channel
            $channel = Channel::where('user_id', $instance->user_id)
                ->where('type', 'whatsapp')
                ->where('page_id', $instance->instance_name)
                ->first();

            if (!$channel) {
                Log::warning("WhatsApp channel not found for unified inbox", [
                    'instance_name' => $instance->instance_name,
                    'user_id' => $instance->user_id,
                ]);
                return;
            }

            // Format sender name if empty or just a dot
            if (empty($fromName) || $fromName === '.') {
                $fromName = $this->formatPhoneNumber($fromPhone);
                Log::info("Using phone number as name in unified inbox", [
                    'from_phone' => $fromPhone,
                    'formatted_name' => $fromName,
                ]);
            } else {
                Log::info("Using pushName from WhatsApp", [
                    'from_phone' => $fromPhone,
                    'push_name' => $fromName,
                ]);
            }

            // Find or create conversation
            $conversation = Conversation::where('channel_id', $channel->id)
                ->where('sender_id', $fromPhone)
                ->first();

            if (!$conversation) {
                $conversation = Conversation::create([
                    'channel_id' => $channel->id,
                    'business_id' => $channel->business_id,
                    'sender_id' => $fromPhone,
                    'sender_name' => $fromName,
                    'sender_email' => null,
                    'subject' => null,
                    'status' => 'active',
                    'last_message_at' => now(),
                ]);

                Log::info("Created new conversation for WhatsApp", [
                    'conversation_id' => $conversation->id,
                    'sender_id' => $fromPhone,
                    'sender_name' => $fromName,
                ]);
            } else {
                // Update conversation metadata
                // Only update sender_name if we have a real name (not empty, not just a dot)
                // and the current sender_name is empty or a phone number format
                $shouldUpdateName = !empty($fromName) && 
                                    $fromName !== '.' && 
                                    (empty($conversation->sender_name) || 
                                     strpos($conversation->sender_name, '+') === 0 ||
                                     preg_match('/^\d+$/', $conversation->sender_name));
                
                $updateData = ['last_message_at' => now()];
                if ($shouldUpdateName) {
                    $updateData['sender_name'] = $fromName;
                    Log::info("Updating conversation sender_name", [
                        'conversation_id' => $conversation->id,
                        'old_name' => $conversation->sender_name,
                        'new_name' => $fromName,
                    ]);
                }
                
                $conversation->update($updateData);
            }

            // Create message in unified inbox
            $message = Message::create([
                'conversation_id' => $conversation->id,
                'content' => $body ?? $media['caption'] ?? '',
                'type' => $messageType,
                'direction' => $fromMe ? 'outbound' : 'inbound',
                'status' => $fromMe ? 'sent' : 'received',
                'is_ai' => false,
                'source' => 'whatsapp',
                'send_status' => 'delivered',
                'media_url' => $media['url'] ?? null,
                'media_type' => $media['type'] ?? null,
                'mime_type' => $media['mimetype'] ?? null,
                'file_name' => is_string($media['filename'] ?? null) ? $media['filename'] : null,
                'file_size' => is_int($media['file_size'] ?? null) ? $media['file_size'] : null,
                'duration' => is_int($media['duration'] ?? null) ? $media['duration'] : null,
                'whatsapp_message_id' => $messageKey['id'] ?? null,
                'whatsapp_remote_jid' => $messageKey['remoteJid'] ?? $fromPhone,
                'whatsapp_from_me' => $fromMe,
                'metadata' => [
                    'whatsapp_key' => $messageKey,
                    'media' => $media,
                ],
            ]);

            // Broadcast for real-time UI updates
            if ($channel->user_id) {
                broadcast(new \App\Events\MessageReceived($message, $conversation, $channel->user_id));
            }

            // Trigger AI auto-reply only for incoming messages
            if (!$fromMe && $channel->ai_enabled) {
                \App\Jobs\ProcessAutoReply::dispatch($message->id);
            }

            Log::info("WhatsApp message saved to unified inbox", [
                'message_id' => $message->id,
                'conversation_id' => $conversation->id,
            ]);

        } catch (\Exception $e) {
            Log::error("Failed to save WhatsApp message to unified inbox: {$e->getMessage()}", [
                'instance_id' => $instance->id,
                'from_phone' => $fromPhone,
            ]);
        }
    }

    /**
     * Handle message status update
     */
    protected function handleMessageUpdate(array $event): void
    {
        $instanceName = $event['instance'] ?? null;
        $messageKey = $event['data']['key'] ?? [];
        $update = $event['data']['update'] ?? [];

        if (!$instanceName) {
            return;
        }

        $remoteMessageId = $messageKey['id'] ?? null;
        if (!$remoteMessageId) {
            return;
        }

        $message = WhatsAppMessage::where('remote_message_id', $remoteMessageId)->first();
        if (!$message) {
            return;
        }

        $status = $update['status'] ?? null;
        if ($status) {
            $message->status = match ($status) {
                'ERROR' => 'failed',
                'PENDING' => 'pending',
                'SERVER_ACK' => 'sent',
                'DELIVERY_ACK' => 'delivered',
                'READ' => 'read',
                default => $message->status,
            };

            if ($status === 'DELIVERY_ACK') {
                $message->delivered_at = now();
            } elseif ($status === 'READ') {
                $message->read_at = now();
            }

            $message->save();
        }
    }

    /**
     * Handle a WhatsApp reaction received through Evolution webhooks.
     */
    protected function handleMessageReaction(array $event): void
    {
        $data = $event['data'] ?? [];
        if (array_is_list($data)) {
            foreach ($data as $item) {
                $this->applyReactionPayload($item, $event);
            }
            return;
        }

        $this->applyReactionPayload($data, $event);
    }

    protected function applyReactionPayload(array $data, array $event): void
    {
        $reaction = $data['reaction'] ?? $data['message']['reactionMessage'] ?? null;
        if (!$reaction) {
            return;
        }

        $emoji = $reaction['text'] ?? $reaction['emoji'] ?? $reaction;
        if (!is_string($emoji)) {
            return;
        }

        $targetKey = $reaction['key'] ?? $data['key'] ?? [];
        $targetId = $targetKey['id'] ?? $reaction['messageId'] ?? null;
        $remoteJid = $targetKey['remoteJid'] ?? ($data['key']['remoteJid'] ?? null);

        if (!$targetId) {
            return;
        }

        $message = Message::where('whatsapp_message_id', $targetId)
            ->when($remoteJid, fn ($query) => $query->where('whatsapp_remote_jid', $remoteJid))
            ->with('conversation.channel')
            ->first();

        if (!$message) {
            Log::warning('Reaction target message not found', [
                'target_id' => $targetId,
                'remote_jid' => $remoteJid,
            ]);
            return;
        }

        $reactions = $message->reactions ?? [];
        $actor = ($data['key']['fromMe'] ?? false) ? 'business' : 'contact';
        $reactions = array_values(array_filter($reactions, fn ($item) => ($item['actor'] ?? null) !== $actor));

        if ($emoji !== '') {
            $reactions[] = [
                'emoji' => $emoji,
                'actor' => $actor,
                'remote_jid' => $remoteJid,
                'created_at' => now()->toISOString(),
            ];
        }

        $message->update(['reactions' => $reactions]);

        $channel = $message->conversation->channel;
        if ($channel?->user_id) {
            broadcast(new \App\Events\MessageReceived($message->fresh(), $message->conversation, $channel->user_id));
        }
    }

    /**
     * Handle connection status update
     */
    protected function handleConnectionUpdate(array $event): void
    {
        $instanceName = $event['instance'] ?? null;
        $state = $event['data']['state'] ?? null;

        if (!$instanceName || !$state) {
            return;
        }

        $instance = WhatsAppInstance::where('instance_name', $instanceName)->first();
        if (!$instance) {
            return;
        }

        $instance->status = match ($state) {
            'open' => 'connected',
            'close' => 'disconnected',
            'connecting' => 'connecting',
            default => $instance->status,
        };

        if ($state === 'open') {
            $instance->connected_at = now();
            $instance->disconnected_at = null;

            // Create or update Channel for WhatsApp integration
            $this->ensureWhatsAppChannel($instance);
        } elseif ($state === 'close') {
            $instance->disconnected_at = now();
        }

        $instance->save();

        Log::info("Connection status updated for {$instanceName}: {$state}");
    }

    /**
     * Ensure a Channel row exists for WhatsApp integration
     */
    protected function ensureWhatsAppChannel(WhatsAppInstance $instance): void
    {
        try {
            $channel = Channel::where('user_id', $instance->user_id)
                ->where('type', 'whatsapp')
                ->where('page_id', $instance->instance_name)
                ->first();

            if (!$channel) {
                $channel = Channel::create([
                    'user_id' => $instance->user_id,
                    'business_id' => null,
                    'type' => 'whatsapp',
                    'page_id' => $instance->instance_name,
                    'page_name' => $instance->profile_name ?? $instance->instance_name,
                    'access_token' => '', // Empty for WhatsApp, instance_name stored in page_id
                    'status' => 'connected',
                    'connected_at' => now(),
                    'ai_enabled' => false,
                ]);

                Log::info("Created WhatsApp channel for instance", [
                    'instance_name' => $instance->instance_name,
                    'channel_id' => $channel->id,
                ]);
            } else {
                // Update existing channel status
                $channel->update([
                    'status' => 'connected',
                    'connected_at' => now(),
                    'page_name' => $instance->profile_name ?? $instance->instance_name,
                ]);

                Log::info("Updated WhatsApp channel status", [
                    'instance_name' => $instance->instance_name,
                    'channel_id' => $channel->id,
                ]);
            }
        } catch (\Exception $e) {
            Log::error("Failed to ensure WhatsApp channel: {$e->getMessage()}", [
                'instance_id' => $instance->id,
            ]);
        }
    }

    /**
     * Handle instance status update
     */
    protected function handleStatusInstance(array $event): void
    {
        $instanceName = $event['instance'] ?? null;
        $status = $event['data']['status'] ?? null;

        if (!$instanceName || !$status) {
            return;
        }

        $instance = WhatsAppInstance::where('instance_name', $instanceName)->first();
        if (!$instance) {
            return;
        }

        // Update instance metadata with status info
        $metadata = $instance->metadata ?? [];
        $metadata['status_update'] = $event['data'];
        $instance->metadata = $metadata;
        $instance->save();

        Log::info("Instance status updated for {$instanceName}: {$status}");
    }

    /**
     * Detect message type from content
     */
    protected function detectMessageType(array $messageContent): string
    {
        if ($this->isReactionMessage($messageContent)) {
            return 'reaction';
        }
        if (isset($messageContent['conversation'])) {
            return 'text';
        }
        if (isset($messageContent['extendedTextMessage'])) {
            return 'text';
        }
        if (isset($messageContent['imageMessage'])) {
            return 'image';
        }
        if (isset($messageContent['videoMessage'])) {
            return 'video';
        }
        if (isset($messageContent['audioMessage'])) {
            return 'audio';
        }
        if (isset($messageContent['documentMessage'])) {
            return 'document';
        }
        if (isset($messageContent['locationMessage'])) {
            return 'location';
        }
        if (isset($messageContent['contactMessage'])) {
            return 'contact';
        }

        return 'text';
    }

    /**
     * Extract media information from message
     */
    protected function extractMedia(array $messageContent): ?array
    {
        $mediaTypes = ['imageMessage', 'videoMessage', 'audioMessage', 'documentMessage'];

        foreach ($mediaTypes as $type) {
            if (isset($messageContent[$type])) {
                return [
                    'type' => str_replace('Message', '', $type),
                    'url' => $messageContent[$type]['url'] ?? null,
                    'mimetype' => $messageContent[$type]['mimetype'] ?? null,
                    'caption' => $messageContent[$type]['caption'] ?? null,
                    'filename' => $messageContent[$type]['fileName'] ?? null,
                ];
            }
        }

        return null;
    }

    protected function extractAndStoreMedia(array $messageContent, array $event, string $instanceName, array $messageKey): ?array
    {
        $media = $this->extractMedia($messageContent);
        if (!$media) {
            return null;
        }

        $mediaNode = $this->getMediaNode($messageContent);
        $media['file_size'] = $this->normalizeNumericValue($mediaNode['fileLength'] ?? $mediaNode['fileSize'] ?? null);
        $media['duration'] = $this->normalizeNumericValue($mediaNode['seconds'] ?? $mediaNode['duration'] ?? null);

        $storedUrl = $this->storeIncomingMedia($mediaNode, $media, $event, $instanceName, $messageKey);
        if ($storedUrl) {
            $media['remote_url'] = $media['url'] ?? null;
            $media['url'] = $storedUrl;
        }

        return $media;
    }

    protected function getMediaNode(array $messageContent): array
    {
        foreach (['imageMessage', 'videoMessage', 'audioMessage', 'documentMessage'] as $type) {
            if (isset($messageContent[$type])) {
                return $messageContent[$type];
            }
        }

        return [];
    }

    protected function storeIncomingMedia(array $mediaNode, array $media, array $event, string $instanceName, array $messageKey): ?string
    {
        $binary = null;
        $mimeType = $media['mimetype'] ?? 'application/octet-stream';
        $extension = $this->extensionFromMime($mimeType, $media['filename'] ?? null);

        $base64 = $mediaNode['base64'] ?? $event['data']['message']['base64'] ?? $event['data']['base64'] ?? null;
        if (!$base64 && !empty($messageKey['id'])) {
            try {
                $base64Response = $this->getBase64FromMediaMessage(
                    $instanceName,
                    $messageKey,
                    str_starts_with($mimeType, 'audio/')
                );

                $base64 = $base64Response['base64']
                    ?? $base64Response['data']['base64']
                    ?? $base64Response['media']
                    ?? $base64Response['data']['media']
                    ?? null;

                $responseMime = $base64Response['mimetype']
                    ?? $base64Response['mimeType']
                    ?? $base64Response['data']['mimetype']
                    ?? $base64Response['data']['mimeType']
                    ?? null;

                if ($responseMime) {
                    $mimeType = $responseMime;
                    $extension = $this->extensionFromMime($mimeType, $media['filename'] ?? null);
                }
            } catch (Exception $e) {
                Log::warning('Failed to fetch WhatsApp media base64 from Evolution', [
                    'instance' => $instanceName,
                    'message_id' => $messageKey['id'] ?? null,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if (is_string($base64) && $base64 !== '') {
            $binary = base64_decode(preg_replace('/^data:[^;]+;base64,/', '', $base64), true);
        }

        if (!$binary && !empty($media['url']) && !str_contains($media['url'], '.enc')) {
            try {
                $response = Http::timeout($this->timeout)
                    ->withHeaders(['apikey' => $this->apiKey])
                    ->get($media['url']);
                if ($response->successful()) {
                    $binary = $response->body();
                }
            } catch (Exception $e) {
                Log::warning('Failed to download WhatsApp media', [
                    'url' => $media['url'],
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if (!$binary) {
            return $media['url'] ?? null;
        }

        $path = 'whatsapp/' . now()->format('Y/m') . '/' . Str::uuid() . '.' . $extension;
        $disk = config('services.evolution.media_disk', 'public');
        Storage::disk($disk)->put($path, $binary);

        return Storage::disk($disk)->url($path);
    }

    protected function extensionFromMime(string $mimeType, ?string $filename = null): string
    {
        if ($filename && pathinfo($filename, PATHINFO_EXTENSION)) {
            return strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        }

        $mimeType = strtolower(trim(explode(';', $mimeType)[0]));

        return match ($mimeType) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            'video/mp4' => 'mp4',
            'audio/ogg' => 'ogg',
            'audio/mpeg' => 'mp3',
            'audio/mp4' => 'm4a',
            'application/pdf' => 'pdf',
            default => 'bin',
        };
    }

    protected function normalizeNumericValue(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_float($value) || is_numeric($value)) {
            return (int) $value;
        }

        if (is_array($value) && array_key_exists('low', $value)) {
            $low = (int) ($value['low'] ?? 0);
            $high = (int) ($value['high'] ?? 0);

            return ($high * 4294967296) + $low;
        }

        return null;
    }

    protected function isReactionMessage(array $messageContent): bool
    {
        return isset($messageContent['reactionMessage']);
    }

    /**
     * Format WhatsApp phone number for display
     */
    protected function formatPhoneNumber(?string $phone): string
    {
        if (!$phone) {
            return 'Unknown';
        }

        // Remove @s.whatsapp.net suffix if present
        $phone = str_replace('@s.whatsapp.net', '', $phone);
        
        // Remove any other suffixes
        $phone = preg_replace('/@.+$/', '', $phone);
        
        // If it starts with country code, format it nicely
        if (preg_match('/^(\d{1,3})(\d+)$/', $phone, $matches)) {
            $countryCode = $matches[1];
            $number = $matches[2];
            return "+{$countryCode} {$number}";
        }

        return $phone;
    }
}
