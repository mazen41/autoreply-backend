<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\Channel;
use App\Services\EvolutionApiService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Google\Client as GoogleClient;
use Google\Service\Gmail;

class InboxController extends Controller
{
    private function conversationQuery(Request $request, string $permission = 'conversation_read')
    {
        $business = app(\App\Services\ActiveBusiness::class)->resolve($request, permission: $permission);
        return Conversation::where('business_id', $business->id);
    }

    /**
     * List all conversations for the authenticated user, newest first.
     * Supports search and advanced filtering.
     */
    public function index(Request $request)
    {
        $business = app(\App\Services\ActiveBusiness::class)->resolve($request, permission: 'conversation_read');
        $userId = (int) $request->user()->id;
        $query = Conversation::where('business_id', $business->id)
            ->with([
                'channel:id,type,page_name,page_id',
                'bot:id,name',
                'latestMessage',
            ])
            ->select(['id', 'channel_id', 'business_id', 'bot_id', 'sender_id', 'sender_name', 'sender_email', 'subject', 'status', 'ai_enabled', 'requires_human', 'escalated_at', 'escalation_reason', 'last_message_at', 'assigned_agent_id', 'assigned_at'])
            // Unread count within the 24h unread window — matches the
            // `unread` filter below and the realtime contract's
            // unread_count (InboxBroadcastPayload::unreadCount).
            ->withCount(['messages as unread_count' => function ($q) use ($userId) {
                $q->where('direction', 'inbound')
                  ->where('created_at', '>=', now()->subHours(24))
                  ->whereRaw('messages.created_at > COALESCE((SELECT conversation_reads.read_at FROM conversation_reads WHERE conversation_reads.conversation_id = messages.conversation_id AND conversation_reads.user_id = ? LIMIT 1), ?)', [$userId, now()->subHours(24)]);
            }]);

        // Agent-specific filter
        if ($request->has('assigned_to_me')) {
            $query->where('assigned_agent_id', auth()->id());
        }

        // Search functionality
        if ($request->has('search') && !empty($request->search)) {
            $searchTerm = $request->search;
            $query->where(function ($q) use ($searchTerm) {
                $q->where('sender_name', 'like', "%{$searchTerm}%")
                  ->orWhere('sender_email', 'like', "%{$searchTerm}%")
                  ->orWhere('sender_id', 'like', "%{$searchTerm}%")
                  ->orWhereHas('messages', function ($messageQuery) use ($searchTerm) {
                      $messageQuery->where('content', 'like', "%{$searchTerm}%");
                  });
            });
        }

        // Channel type filter
        if ($request->has('channel_type') && !empty($request->channel_type)) {
            $query->whereHas('channel', function ($q) use ($request) {
                $q->where('type', $request->channel_type);
            });
        }

        // Channel ID filter (specific channel account) — verify ownership
        if ($request->has('channel_id') && !empty($request->channel_id)) {
            $channelId = (int) $request->channel_id;
            $channelOwned = \App\Models\Channel::where('id', $channelId)
                ->where('business_id', $business->id)
                ->exists();
            if (!$channelOwned) {
                // Channel not owned by this user — return empty results
                $query->whereRaw('1 = 0');
            } else {
                $query->where('channel_id', $channelId);
            }
        }

        // Bot filter — verify bot belongs to a channel owned by this user
        if ($request->has('bot_id') && !empty($request->bot_id)) {
            $botId = (int) $request->bot_id;
            $botOwned = \App\Models\Bot::where('id', $botId)
                ->where('business_profile_id', $business->id)
                ->exists();
            if (!$botOwned) {
                // Bot not owned by this user — return empty results
                $query->whereRaw('1 = 0');
            } else {
                $query->where('bot_id', $botId);
            }
        }

        // Status filter
        if ($request->has('status') && !empty($request->status)) {
            $query->where('status', $request->status);
        }

        // AI enabled filter
        if ($request->has('ai_enabled') && $request->ai_enabled !== null) {
            $query->where('ai_enabled', $request->boolean('ai_enabled'));
        }

        // Unread filter (conversations with recent inbound messages)
        if ($request->has('unread') && $request->boolean('unread')) {
            $query->whereHas('messages', function ($q) use ($userId) {
                $q->where('direction', 'inbound')
                  ->where('created_at', '>=', now()->subHours(24))
                  ->whereRaw('messages.created_at > COALESCE((SELECT conversation_reads.read_at FROM conversation_reads WHERE conversation_reads.conversation_id = messages.conversation_id AND conversation_reads.user_id = ? LIMIT 1), ?)', [$userId, now()->subHours(24)]);
            });
        }

        // Date range filter
        if ($request->has('date_from') && !empty($request->date_from)) {
            $query->where('last_message_at', '>=', $request->date_from);
        }
        if ($request->has('date_to') && !empty($request->date_to)) {
            $query->where('last_message_at', '<=', $request->date_to);
        }

        // Escalation queue filter (requires human intervention)
        if ($request->has('requires_human') && $request->boolean('requires_human')) {
            $query->where('requires_human', true)
                  ->whereNotNull('escalated_at')
                  ->orderBy('escalated_at', 'desc');
        } else {
            // Default sort by last message time
            $query->orderBy('last_message_at', 'desc');
        }

        $conversations = $query->paginate(50);

        return response()->json($conversations);
    }

    /**
     * Get all messages in a conversation (oldest first for chat display).
     */
    public function messages(Request $request, $conversationId)
    {
        $conversation = $this->conversationQuery($request, 'conversation_read')
            ->with('channel:id,type,page_name')
            ->findOrFail($conversationId);

        $messages = Message::where('conversation_id', $conversationId)
            ->orderBy('created_at', 'asc')
            ->get();

        return response()->json([
            'conversation' => $conversation,
            'messages'     => $messages,
        ]);
    }

    /** Mark this user's inbox view as read without changing another agent's state. */
    public function markRead(Request $request, int $businessId, int $conversationId)
    {
        $business = app(\App\Services\ActiveBusiness::class)->resolve(
            $request,
            permission: 'conversation_read',
            routeBusinessId: $businessId,
        );
        $conversation = Conversation::where('business_id', $business->id)->findOrFail($conversationId);
        $now = now();

        DB::table('conversation_reads')->upsert([[
            'conversation_id' => $conversation->id,
            'user_id' => $request->user()->id,
            'read_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]], ['conversation_id', 'user_id'], ['read_at', 'updated_at']);

        return response()->json(['success' => true, 'read_at' => $now->toISOString()]);
    }

    /**
     * Send a manual reply from the business owner.
     */
    public function reply(Request $request, $conversationId)
    {
        $request->validate(['message' => 'required|string|max:2000']);

        $conversation = $this->conversationQuery($request, 'conversation_write')
            ->findOrFail($conversationId);

        $channel = $conversation->channel;

        // Handle different channel types
        if ($channel->type === 'gmail') {
            return $this->sendGmailReply($request, $conversation, $channel);
        }

        if ($channel->type === 'whatsapp') {
            return $this->sendWhatsAppReply($request, $conversation, $channel);
        }

        // Handle Facebook/Instagram
        return $this->sendFacebookReply($request, $conversation, $channel);
    }

    /**
     * Send a media reply from the business owner.
     */
    public function mediaReply(Request $request, $conversationId)
    {
        $request->validate([
            'file' => 'required|file|max:51200',
            'caption' => 'nullable|string|max:2000',
            'media_type' => 'nullable|in:image,audio,video,document',
            'voice_note' => 'nullable|boolean',
        ]);

        $conversation = $this->conversationQuery($request, 'conversation_write')
            ->findOrFail($conversationId);

        $channel = $conversation->channel;
        if ($channel->type !== 'whatsapp') {
            return response()->json(['error' => 'Media uploads are only supported for WhatsApp conversations right now'], 400);
        }
        if ($blocked = $this->planUsageBlock($channel, $conversation, (string) $request->caption, 'whatsapp_media')) {
            return $blocked;
        }

        $isCloud = ($channel->metadata['whatsapp_provider'] ?? null) === 'cloud';
        if ($isCloud && !$this->withinWhatsAppCustomerCareWindow($conversation)) {
            $reason = 'A WhatsApp template is required because the 24-hour customer care window has expired.';
            $blocked = $this->recordBlockedOutboundMessage($channel, $conversation, $caption, $reason, 'whatsapp_media');
            return response()->json(['error' => $reason, 'message' => $blocked], 422);
        }
        if ($isCloud && ($request->media_type ?: $this->mediaTypeFromMime($request->file('file')->getMimeType() ?: '')) !== 'image') {
            $reason = 'WhatsApp Cloud media replies currently support images only.';
            $blocked = $this->recordBlockedOutboundMessage($channel, $conversation, (string) $request->caption, $reason, 'whatsapp_media');
            return response()->json(['error' => $reason, 'message' => $blocked], 422);
        }
        if (!$isCloud && ($channel->metadata['unofficial_risk_accepted'] ?? false) !== true) {
            $reason = 'Acknowledge the unofficial WhatsApp connection risk before sending messages.';
            $blocked = $this->recordBlockedOutboundMessage($channel, $conversation, (string) $request->caption, $reason, 'whatsapp_media');
            return response()->json(['error' => $reason, 'message' => $blocked], 403);
        }

        try {
            $file = $request->file('file');
            $mimeType = $file->getMimeType() ?: 'application/octet-stream';
            $mediaType = $request->media_type ?: $this->mediaTypeFromMime($mimeType);
            $fileName = $file->getClientOriginalName() ?: Str::uuid() . '.' . ($file->guessExtension() ?: 'bin');

            // Evolution API validates that `media` is a well-formed URL (or base64).
            // Raw spaces (and other unsafe characters) in a filename produce an
            // invalid URL once appended to the storage path — e.g. a screenshot
            // named "Screenshot 2026-07-18 154008.png" breaks the URL Evolution
            // receives, and it correctly rejects it with "Owned media must be a
            // url or base64". Build a URL-safe filename for the actual storage
            // path, but keep the original (with spaces) for display/metadata.
            $safeFileName = preg_replace('/[^A-Za-z0-9._-]+/', '_', $fileName);
            $disk = config('services.evolution.media_disk', 'public');
            $path = $file->storeAs('whatsapp/outgoing/' . now()->format('Y/m'), Str::uuid() . '-' . $safeFileName, $disk);
            $mediaUrl = Storage::disk($disk)->url($path);

            $instanceName = $channel->page_id;
            $caption = $request->caption ?? '';

            if ($isCloud) {
                $cloudResult = app(\App\Services\WhatsAppCloudApiService::class)
                    ->sendImage($channel, $conversation->sender_id, $mediaUrl, $caption);
                if (empty($cloudResult['success'])) {
                    $reason = (string) ($cloudResult['reason'] ?? 'WhatsApp Cloud provider rejected the image.');
                    $failed = $this->recordFailedOutboundMessage($channel, $conversation, $caption, $reason, 'whatsapp_media');
                    return response()->json(['error' => 'Failed to send WhatsApp Cloud image', 'message' => $failed], 502);
                }
                $response = ['key' => ['id' => $cloudResult['message_id'] ?? null, 'remoteJid' => $conversation->sender_id, 'fromMe' => true]];
            } else {
                $whatsappService = new EvolutionApiService();
                if ($mediaType === 'audio' && $request->boolean('voice_note')) {
                    $response = $whatsappService->sendWhatsAppAudio($instanceName, $conversation->sender_id, $mediaUrl);
                } else {
                    $response = $whatsappService->sendMediaMessage(
                        $instanceName,
                        $conversation->sender_id,
                        $mediaUrl,
                        $caption,
                        $mediaType,
                        $mimeType,
                        $fileName
                    );
                }
            }

            if (!isset($response['key']['id'])) {
                Log::error('WhatsApp media reply failed', ['response_keys' => array_keys($response)]);
                $reason = 'WhatsApp provider did not return a media message ID.';
                $failed = $this->recordFailedOutboundMessage($channel, $conversation, $caption, $reason, 'whatsapp_media');
                return response()->json(['error' => $reason, 'message' => $failed], 502);
            }

            $message = Message::create([
                'conversation_id' => $conversation->id,
                'content' => $caption,
                'type' => $mediaType,
                'direction' => 'outbound',
                'is_ai' => false,
                'status' => 'manual',
                'source' => 'whatsapp',
                'send_status' => 'sent',
                'delivery_status' => 'sent',
                'media_url' => $mediaUrl,
                'media_type' => $mediaType,
                'mime_type' => $mimeType,
                'file_name' => $fileName,
                'file_size' => $file->getSize(),
                'whatsapp_message_id' => $response['key']['id'] ?? null,
                'whatsapp_remote_jid' => $response['key']['remoteJid'] ?? $conversation->sender_id,
                'whatsapp_from_me' => $response['key']['fromMe'] ?? true,
                'metadata' => ['evolution_response' => $response],
            ]);

            $conversation->update(['last_message_at' => now()]);

            \App\Models\WhatsAppMessage::create([
                'whatsapp_instance_id' => \App\Models\WhatsAppInstance::where('instance_name', $instanceName)->first()?->id,
                'user_id' => $channel->user_id,
                'message_id' => $response['key']['id'] ?? null,
                'remote_message_id' => $response['key']['id'] ?? null,
                'direction' => 'outgoing',
                'from_phone' => null,
                'from_name' => null,
                'to_phone' => $conversation->sender_id,
                'body' => $caption,
                'message_type' => $mediaType,
                'media' => [
                    'url' => $mediaUrl,
                    'type' => $mediaType,
                    'mimetype' => $mimeType,
                    'filename' => $fileName,
                    'file_size' => $file->getSize(),
                    'caption' => $caption,
                ],
                'metadata' => ['evolution_response' => $response, 'unified_message_id' => $message->id],
                'status' => 'sent',
                'sent_at' => now(),
            ]);

            if ($channel->user_id) {
                broadcast(new \App\Events\MessageReceived($message, $conversation, $channel->user_id));
            }

            return response()->json(['success' => true, 'message' => $message]);
        } catch (\Exception $e) {
            Log::error('WhatsApp media reply failed', ['exception_type' => get_class($e), 'channel_id' => $channel->id]);
            return response()->json(['error' => 'Failed to send WhatsApp media: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Stream an attachment for preview or download after verifying ownership.
     */
    public function media(Request $request, $messageId)
    {
        $user = auth('sanctum')->user();

        if (!$user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $business = app(\App\Services\ActiveBusiness::class)->resolve($request, permission: 'conversation_read');
        $message = Message::whereHas('conversation', fn ($q) => $q->where('business_id', $business->id))
            ->findOrFail($messageId);

        if (!$message->media_url) {
            return response()->json(['error' => 'Attachment not found'], 404);
        }

        $disk = data_get($message->metadata, 'media.storage_disk')
            ?: config('services.evolution.media_disk', 'public');
        $path = $this->storagePathFromMediaUrl($message->media_url);

        if ($disk === config('services.evolution.incoming_media_disk', 'private_media')
            && $path && !app(\App\Services\IncomingMediaStorage::class)->absolutePath($path, $disk)) {
            return response()->json(['error' => 'File unavailable'], 404);
        }

        if (!$path || !Storage::disk($disk)->exists($path)) {
            Log::warning('Message media missing from storage', [
                'message_id' => $message->id,
                'disk' => $disk,
                'media_url' => $message->media_url,
                'resolved_path' => $path,
            ]);

            return response()->json(['error' => 'File unavailable'], 404);
        }

        $filename = $message->file_name ?: basename($path);
        $mimeType = $message->mime_type ?: Storage::disk($disk)->mimeType($path) ?: 'application/octet-stream';
        $disposition = $request->query('disposition') === 'inline' ? 'inline' : 'attachment';

        return Storage::disk($disk)->response($path, $filename, [
            'Content-Type' => $mimeType,
            'Content-Disposition' => $disposition . '; filename="' . addslashes($filename) . '"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Send reply via Gmail API
     */
    private function sendGmailReply(Request $request, Conversation $conversation, Channel $channel)
    {
        if ($blocked = $this->planUsageBlock($channel, $conversation, $request->message, 'gmail')) {
            return $blocked;
        }

        try {
            $client = $this->getGmailClient($channel);
            if (!$client) {
                Log::error('Gmail client failed for reply', ['channel_id' => $channel->id]);
                return response()->json(['error' => 'Gmail authentication failed'], 500);
            }

            $gmail = new Gmail($client);

            // Create MIME message
            $rawMessage = $this->createMimeMessage(
                $conversation->sender_email ?? $conversation->sender_id,
                $channel->page_name,
                $conversation->subject ?? 'Re',
                $request->message
            );

            // Send the message
            $msg = new Gmail\Message();
            $msg->setRaw(base64_encode($rawMessage));
            $sentMessage = $gmail->users_messages->send('me', $msg);

            // Save to DB
            $message = Message::create([
                'conversation_id' => $conversation->id,
                'content'         => $request->message,
                'direction'       => 'outbound',
                'is_ai'           => false,
                'status'          => 'manual',
                'send_status'     => 'sent',
                'delivery_status' => 'sent',
                'gmail_message_id' => $sentMessage->getId(),
            ]);

            $conversation->update(['last_message_at' => now()]);

            if ($channel->user_id) {
                broadcast(new \App\Events\MessageReceived($message, $conversation, $channel->user_id));
            }

            return response()->json(['success' => true, 'message' => $message]);

        } catch (\Exception $e) {
            Log::error('Gmail reply failed', ['exception_type' => get_class($e), 'channel_id' => $channel->id]);
            $reason = 'Gmail provider rejected or could not confirm the reply.';
            $failed = $this->recordFailedOutboundMessage($channel, $conversation, $request->message, $reason, 'gmail');
            return response()->json(['error' => $reason, 'message' => $failed], 502);
        }
    }

    /**
     * Send reply via Facebook Graph API
     */
    private function sendFacebookReply(Request $request, Conversation $conversation, Channel $channel)
    {
        if ($blocked = $this->planUsageBlock($channel, $conversation, $request->message, 'facebook')) {
            return $blocked;
        }

        // The Channel model's accessor already decrypts access_token — do NOT
        // call decrypt() again or it will throw "The payload is invalid."
        $accessToken = $channel->access_token;

        $fbResponse = Http::timeout(10)
            ->post(
                "https://graph.facebook.com/v19.0/me/messages?access_token={$accessToken}",
                [
                    'recipient' => ['id' => $conversation->sender_id],
                    'message'   => ['text' => $request->message],
                ]
            );

        if (!$fbResponse->successful()) {
            Log::error('Manual reply failed', ['http_status' => $fbResponse->status()]);
            $reason = (string) ($fbResponse->json('error.message') ?? $fbResponse->json('error.code') ?? 'Facebook provider rejected the reply.');
            $failed = $this->recordFailedOutboundMessage($channel, $conversation, $request->message, $reason, 'facebook');
            return response()->json(['error' => 'Failed to send message via Facebook', 'message' => $failed], 502);
        }

        // Save to DB
        $message = Message::create([
            'conversation_id' => $conversation->id,
            'content'         => $request->message,
            'direction'       => 'outbound',
            'is_ai'           => false,
            'status'          => 'manual',
            'send_status'     => 'sent',
            'delivery_status' => 'sent',
            'metadata'        => ['platform_message_id' => $fbResponse->json('message_id')],
        ]);

        $conversation->update(['last_message_at' => now()]);

        if ($channel->user_id) {
            broadcast(new \App\Events\MessageReceived($message, $conversation, $channel->user_id));
        }

        return response()->json(['success' => true, 'message' => $message]);
    }

    /**
     * Send reply via WhatsApp Evolution API
     */
    private function sendWhatsAppReply(Request $request, Conversation $conversation, Channel $channel)
    {
        try {
            if ($blocked = $this->planUsageBlock($channel, $conversation, $request->message, 'whatsapp')) {
                return $blocked;
            }

            if (($channel->metadata['whatsapp_provider'] ?? null) !== 'cloud'
                && ($channel->metadata['unofficial_risk_accepted'] ?? false) !== true) {
                $reason = 'Acknowledge the unofficial WhatsApp connection risk before sending messages.';
                $blocked = $this->recordBlockedOutboundMessage($channel, $conversation, $request->message, $reason, 'whatsapp');
                return response()->json(['error' => $reason, 'message' => $blocked], 403);
            }

            if (($channel->metadata['whatsapp_provider'] ?? null) === 'cloud') {
                if (!$this->withinWhatsAppCustomerCareWindow($conversation)) {
                    $reason = 'A WhatsApp template is required because the 24-hour customer care window has expired.';
                    $blocked = $this->recordBlockedOutboundMessage($channel, $conversation, $request->message, $reason, 'whatsapp');
                    return response()->json(['error' => $reason, 'message' => $blocked], 422);
                }

                $message = Message::create([
                    'conversation_id' => $conversation->id,
                    'content' => $request->message,
                    'direction' => 'outbound',
                    'is_ai' => false,
                    'status' => 'manual',
                    'source' => 'whatsapp',
                    'send_status' => 'pending',
                ]);
                $sent = app(\App\Services\ChannelMessageService::class)
                    ->sendMessage($channel, $conversation, $message);
                if (!$sent) {
                    $current = $message->fresh();
                    $statusCode = $current?->send_status === 'blocked' ? 422 : 502;
                    return response()->json([
                        'error' => $current?->error_details ?: 'Failed to send WhatsApp Cloud message',
                        'message' => $current,
                    ], $statusCode);
                }
                $conversation->update(['last_message_at' => now()]);
                if ($channel->user_id) {
                    broadcast(new \App\Events\MessageReceived($message->fresh(), $conversation, $channel->user_id));
                }
                return response()->json(['success' => true, 'message' => $message->fresh()]);
            }

            $whatsappService = new EvolutionApiService();
            $instanceName = $channel->page_id; // We store instance_name in page_id for WhatsApp

            // Send message via Evolution API
            $response = $whatsappService->sendTextMessage(
                $instanceName,
                $conversation->sender_id,
                $request->message
            );

            if (!isset($response['key']['id'])) {
                Log::error('WhatsApp reply failed', ['response_keys' => array_keys($response)]);
                $reason = 'WhatsApp provider did not return a message ID.';
                $failed = $this->recordFailedOutboundMessage($channel, $conversation, $request->message, $reason, 'whatsapp');
                return response()->json(['error' => $reason, 'message' => $failed], 502);
            }

            // Save to DB
            $message = Message::create([
                'conversation_id' => $conversation->id,
                'content'         => $request->message,
                'direction'       => 'outbound',
                'is_ai'           => false,
                'status'          => 'manual',
                'source'          => 'whatsapp',
                'send_status'     => 'sent',
                'delivery_status' => 'sent',
                'whatsapp_message_id' => $response['key']['id'] ?? null,
                'whatsapp_remote_jid' => $response['key']['remoteJid'] ?? $conversation->sender_id,
                'whatsapp_from_me' => $response['key']['fromMe'] ?? true,
                'metadata' => ['evolution_response' => $response],
            ]);

            $conversation->update(['last_message_at' => now()]);

            // Also save to WhatsApp messages table for legacy compatibility
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
                    'to_phone' => null,
                    'body' => $request->message,
                    'message_type' => 'text',
                    'media' => null,
                    'metadata' => ['evolution_response' => $response, 'unified_message_id' => $message->id],
                    'status' => 'sent',
                    'sent_at' => now(),
                ]);
            }

            if ($channel->user_id) {
                broadcast(new \App\Events\MessageReceived($message, $conversation, $channel->user_id));
            }

            return response()->json(['success' => true, 'message' => $message]);

        } catch (\Exception $e) {
            Log::error('WhatsApp reply failed', ['exception_type' => get_class($e), 'channel_id' => $channel->id]);
            $reason = 'WhatsApp provider rejected or could not confirm the reply.';
            $failed = $this->recordFailedOutboundMessage($channel, $conversation, $request->message, $reason, 'whatsapp');
            return response()->json(['error' => $reason, 'message' => $failed], 502);
        }
    }

    private function withinWhatsAppCustomerCareWindow(Conversation $conversation): bool
    {
        $lastInbound = $conversation->messages()->where('direction', 'inbound')->latest('created_at')->first();
        return $lastInbound !== null && $lastInbound->created_at->gt(now()->subHours(24));
    }

    private function planUsageBlock(Channel $channel, Conversation $conversation, string $content, string $source)
    {
        $business = $conversation->business;
        if (!$business) {
            return response()->json(['error' => 'Business context is required to send this message.'], 422);
        }

        $decision = app(\App\Services\UsageMeter::class)->canUse(
            $business,
            \App\Services\UsageMeter::OUTBOUND_MESSAGES,
        );
        if ($decision['allowed']) {
            return null;
        }

        $reason = 'The business plan limit for monthly outbound messages has been reached.';
        $message = $this->recordBlockedOutboundMessage($channel, $conversation, $content, $reason, $source);
        return response()->json(['error' => $reason, 'message' => $message], 403);
    }

    private function recordBlockedOutboundMessage(
        Channel $channel,
        Conversation $conversation,
        string $content,
        string $reason,
        string $source,
    ): Message {
        $message = Message::create([
            'conversation_id' => $conversation->id,
            'content' => $content,
            'direction' => 'outbound',
            'is_ai' => false,
            'status' => 'blocked',
            'source' => $source,
            'send_status' => 'blocked',
            'error_details' => mb_substr($reason, 0, 2000),
        ]);
        if ($channel->user_id) {
            broadcast(new \App\Events\MessageReceived($message, $conversation, $channel->user_id));
        }
        return $message;
    }

    private function recordFailedOutboundMessage(
        Channel $channel,
        Conversation $conversation,
        string $content,
        string $reason,
        string $source,
    ): Message {
        $message = Message::create([
            'conversation_id' => $conversation->id,
            'content' => $content,
            'direction' => 'outbound',
            'is_ai' => false,
            'status' => 'failed',
            'source' => $source,
            'send_status' => 'failed',
            'delivery_status' => 'failed',
            'error_details' => mb_substr($reason, 0, 2000),
        ]);
        if ($channel->user_id) {
            broadcast(new \App\Events\MessageReceived($message, $conversation, $channel->user_id));
        }
        return $message;
    }

    /**
     * Send reaction to a WhatsApp message
     */
    public function reactToMessage(Request $request, $messageId)
    {
        $request->validate([
            'emoji' => 'required|string|max:10',
        ]);

        $business = app(\App\Services\ActiveBusiness::class)->resolve($request, permission: 'conversation_write');
        $message = Message::whereHas('conversation', fn ($q) => $q->where('business_id', $business->id))
            ->with(['conversation.channel'])
            ->findOrFail($messageId);
        $channel = $message->conversation->channel;

        // Only allow reactions for WhatsApp channels
        if ($channel->type !== 'whatsapp') {
            return response()->json(['error' => 'Reactions are only supported for WhatsApp messages'], 400);
        }

        if (($channel->metadata['whatsapp_provider'] ?? null) === 'cloud') {
            return response()->json(['error' => 'WhatsApp Cloud reactions are not supported yet.'], 422);
        }
        if (($channel->metadata['unofficial_risk_accepted'] ?? false) !== true) {
            return response()->json(['error' => 'Acknowledge the unofficial WhatsApp connection risk before sending messages.'], 403);
        }

        try {
            $evolutionService = new EvolutionApiService();
            $instanceName = $channel->page_id;

            if (!$message->whatsapp_message_id) {
                return response()->json(['error' => 'WhatsApp message key not found for this message'], 400);
            }

            $response = $evolutionService->sendReaction(
                $instanceName,
                $message->whatsapp_remote_jid ?? $message->conversation->sender_id,
                $message->whatsapp_message_id,
                $request->emoji
            );

            if (!$response || (isset($response['error']) && $response['error'])) {
                Log::error('WhatsApp reaction failed', [
                    'response_keys' => is_array($response) ? array_keys($response) : [],
                ]);
                return response()->json(['error' => 'Failed to send reaction'], 500);
            }

            // Update message reactions in database
            $currentReactions = $message->reactions ?? [];
            $currentReactions = array_values(array_filter($currentReactions, fn ($item) => ($item['actor'] ?? null) !== 'business'));
            $currentReactions[] = [
                'emoji' => $request->emoji,
                'user_id' => auth()->id(),
                'actor' => 'business',
                'created_at' => now()->toISOString(),
            ];

            $message->update(['reactions' => $currentReactions]);

            // Broadcast the reaction update
            if ($channel->user_id) {
                broadcast(new \App\Events\MessageReceived($message->fresh(), $message->conversation, $channel->user_id));
            }

            return response()->json(['success' => true, 'reactions' => $currentReactions]);

        } catch (\Exception $e) {
            Log::error('WhatsApp reaction error', ['exception_type' => get_class($e), 'message_id' => $messageId]);
            return response()->json(['error' => 'Failed to send reaction: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Get tags for a conversation
     */
    public function getTags(Request $request, $conversationId)
    {
        $conversation = $this->conversationQuery($request, 'conversation_read')
            ->findOrFail($conversationId);

        $tags = \App\Models\ConversationTag::where('conversation_id', $conversationId)
            ->get(['id', 'tag', 'intent', 'confidence', 'created_at']);

        return response()->json($tags);
    }

    /**
     * Add tag to conversation
     */
    public function addTag(Request $request, $conversationId)
    {
        $request->validate([
            'tag' => 'required|string|max:50',
        ]);

        $conversation = $this->conversationQuery($request, 'conversation_write')
            ->findOrFail($conversationId);

        $tag = \App\Models\ConversationTag::firstOrCreate(
            [
                'conversation_id' => $conversationId,
                'tag' => $request->tag
            ],
            [
                'intent' => 'manual',
                'confidence' => 100,
            ]
        );

        // Trigger sequence enrollment for tag-based sequences
        try {
            $sequenceTriggerService = new \App\Services\SequenceTriggerService(
                app(\App\Services\SequenceEnrollmentService::class)
            );
            $sequenceTriggerService->checkAndEnrollForTagAdded($conversation, $request->tag);
        } catch (\Exception $e) {
            \Log::error('Failed to check sequence enrollment for tag', [
                'conversation_id' => $conversationId,
                'tag' => $request->tag,
                'error' => $e->getMessage(),
            ]);
        }

        // Evaluate tag_added automation workflows for this event.
        // Only fire when the tag was actually created by this request —
        // re-POSTing an existing tag is a no-op, not a tag_added event.
        // The event carries the specific tag that was just added; the engine
        // only runs workflows whose trigger type is compatible with the
        // tag_added event, so message-triggered workflows are not re-checked
        // here (and tag workflows are not re-checked per message).
        if ($tag->wasRecentlyCreated) {
            try {
                app(\App\Services\AutomationEngine::class)
                    ->executeWorkflowsForEvent(
                        \App\Services\AutomationEngine::EVENT_TAG_ADDED,
                        $conversation,
                        ['tag' => $request->tag, 'tag_event_id' => $tag->id]
                    );
            } catch (\Exception $e) {
                \Log::error('Failed to evaluate tag_added workflows', [
                    'conversation_id' => $conversationId,
                    'tag' => $request->tag,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Broadcast the tag change so tags reflect in every
        // agent's view of this conversation without a refresh.
        broadcast(new \App\Events\ConversationUpdated($conversation->fresh()));

        return response()->json($tag);
    }

    /**
     * Remove tag from conversation
     */
    public function removeTag(Request $request, $conversationId, $tagId)
    {
        $conversation = $this->conversationQuery($request, 'conversation_write')
            ->findOrFail($conversationId);

        $tag = \App\Models\ConversationTag::where('id', $tagId)
            ->where('conversation_id', $conversationId)
            ->firstOrFail();

        $tag->delete();

        // Broadcast the tag removal in realtime.
        broadcast(new \App\Events\ConversationUpdated($conversation->fresh()));

        return response()->json(['success' => true]);
    }

    /**
     * Get all unique tags for user's conversations
     */
    public function getAllTags(Request $request)
    {
        $business = app(\App\Services\ActiveBusiness::class)->resolve($request, permission: 'conversation_read');
        $tags = \App\Models\ConversationTag::whereHas('conversation', fn ($q) => $q->where('business_id', $business->id))
            ->select('tag')
            ->distinct()
            ->get()
            ->pluck('tag');

        return response()->json($tags);
    }

    private function mediaTypeFromMime(string $mimeType): string
    {
        if (str_starts_with($mimeType, 'image/')) {
            return 'image';
        }
        if (str_starts_with($mimeType, 'audio/')) {
            return 'audio';
        }
        if (str_starts_with($mimeType, 'video/')) {
            return 'video';
        }

        return 'document';
    }

    private function storagePathFromMediaUrl(string $mediaUrl): ?string
    {
        if (str_contains($mediaUrl, "\0") || str_contains($mediaUrl, '\\')) {
            return null;
        }

        if (!preg_match('#^https?://#i', $mediaUrl)) {
            $relative = ltrim(rawurldecode($mediaUrl), '/');
            return in_array('..', explode('/', $relative), true) ? null : $relative;
        }

        $path = parse_url($mediaUrl, PHP_URL_PATH);
        if (!$path) {
            return null;
        }

        $storagePrefix = '/storage/';
        $position = strpos($path, $storagePrefix);
        if ($position !== false) {
            $relative = urldecode(substr($path, $position + strlen($storagePrefix)));
            return in_array('..', explode('/', $relative), true) ? null : $relative;
        }

        $relative = ltrim(urldecode($path), '/');
        return in_array('..', explode('/', $relative), true) ? null : $relative;
    }

    public function updateStatus(Request $request, $conversationId)
    {
        $request->validate([
            'status' => 'required|in:open,closed,pending',
        ]);

        $conversation = $this->conversationQuery($request, 'conversation_write')
            ->findOrFail($conversationId);

        $conversation->update(['status' => $request->status]);

        // Cancel active sequence enrollments when conversation is closed
        if ($request->status === 'closed') {
            try {
                $enrollmentService = app(\App\Services\SequenceEnrollmentService::class);
                $enrollmentService->stopEnrollmentsForConversation($conversation, 'conversation_closed');
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error('Failed to stop sequences on conversation close', [
                    'conversation_id' => $conversation->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Broadcast the status change so all agents' inboxes update
        // immediately (list ordering, escalation styling, etc.).
        broadcast(new \App\Events\ConversationUpdated($conversation->fresh()));

        return response()->json([
            'success' => true,
            'conversation' => $conversation->fresh('channel:id,type,page_name', 'latestMessage'),
        ]);
    }

    /**
     * Get authenticated Gmail client
     */
    private function getGmailClient(Channel $channel): ?GoogleClient
    {
        try {
            $tokenData = json_decode(decrypt($channel->getRawOriginal('access_token')), true);
            $client = new GoogleClient();
            $client->setClientId(env('GOOGLE_CLIENT_ID'));
            $client->setClientSecret(env('GOOGLE_CLIENT_SECRET'));
            $client->setRedirectUri(env('GMAIL_REDIRECT_URI', env('GOOGLE_REDIRECT_URI')));
            $client->setAccessToken($tokenData);

            if ($client->isAccessTokenExpired()) {
                $refreshToken = $channel->refresh_token ?? ($tokenData['refresh_token'] ?? null);
                if (!$refreshToken) {
                    Log::error('Gmail token expired and no refresh token', ['channel_id' => $channel->id]);
                    return null;
                }
                try {
                    $refreshToken = decrypt($refreshToken);
                } catch (\Exception $e) {
                    // Already plain string from tokenData
                }
                $client->fetchAccessTokenWithRefreshToken($refreshToken);
                $newToken = $client->getAccessToken();

                Channel::where('id', $channel->id)->update([
                    'access_token' => encrypt(json_encode($newToken)),
                    'updated_at'   => now(),
                ]);
            }

            return $client;
        } catch (\Exception $e) {
            Log::error('Gmail client error', ['exception_type' => get_class($e), 'channel_id' => $channel->id]);
            return null;
        }
    }

    /**
     * Create MIME message for Gmail
     */
    private function createMimeMessage($to, $from, $subject, $body): string
    {
        $boundary = uniqid(rand(), true);
        $headers = [
            "From: {$from}",
            "To: {$to}",
            "Subject: {$subject}",
            "MIME-Version: 1.0",
            "Content-Type: multipart/alternative; boundary={$boundary}",
        ];
        $message = implode("\r\n", $headers) . "\r\n\r\n";
        $message .= "--{$boundary}\r\n";
        $message .= "Content-Type: text/plain; charset=UTF-8\r\n\r\n";
        $message .= $body . "\r\n";
        $message .= "--{$boundary}--\r\n";
        return $message;
    }

    /**
     * Toggle AI for a specific conversation
     */
    public function toggleAi(Request $request, $conversationId)
    {
        $conversation = $this->conversationQuery($request, 'conversation_write')
            ->findOrFail($conversationId);

        $newValue = !$conversation->ai_enabled;

        $conversation->update([
            'ai_enabled' => $newValue
        ]);

        // Broadcast the AI state change so every agent viewing the
        // inbox sees the toggle immediately — no refresh required.
        broadcast(new \App\Events\ConversationUpdated($conversation->fresh()));

        // Re-read from DB to ensure response reflects the persisted value,
        // not the stale in-memory attribute (update() writes to DB but does
        // not automatically refresh the model's attribute cache).
        return response()->json([
            'message'    => 'AI toggled successfully',
            'ai_enabled' => $newValue,
        ]);
    }

    /**
     * Add an internal note to a conversation.
     */
    public function addNote(Request $request, $conversationId)
    {
        $request->validate([
            'content' => 'required|string|max:5000',
        ]);

        $conversation = $this->conversationQuery($request, 'conversation_write')
            ->findOrFail($conversationId);

        $note = \App\Models\CustomerNote::create([
            'customer_id' => $conversation->customer_id,
            'user_id' => auth()->id(),
            'conversation_id' => $conversation->id,
            'content' => $request->input('content'),
        ]);

        // Audit log
        \App\Services\AuditLoggerService::log('conversation.note_added', $note, [
            'conversation_id' => $conversation->id,
        ]);

        return response()->json([
            'message' => 'Note added successfully',
            'note' => $note->load('author:id,name'),
        ], 201);
    }

    /**
     * Update the Bot assigned to a conversation.
     *
     * Allows switching the active Bot for a conversation, or clearing it (null).
     * Validates that the new Bot is active and assigned to the conversation's channel.
     */
    public function updateBot(Request $request, $id)
    {
        $request->validate([
            'bot_id' => 'nullable|integer',
        ]);

        $conversation = $this->conversationQuery($request, 'conversation_write')
            ->with('bot:id,name')
            ->findOrFail($id);

        $botId = $request->input('bot_id');

        if ($botId !== null) {
            $botId = (int) $botId;

            // Verify the bot exists, is active, and is assigned to this conversation's channel
            $bot = \App\Models\Bot::where('id', $botId)
                ->where('status', 'active')
                ->whereHas('channels', function ($q) use ($conversation) {
                    $q->where('channel_id', $conversation->channel_id);
                })
                ->first();

            if (!$bot) {
                return response()->json([
                    'error' => 'Bot not found, inactive, or not assigned to this conversation\'s channel',
                ], 422);
            }
        }

        $conversation->update(['bot_id' => $botId]);

        // Broadcast the bot switch so the conversation header shows the
        // correct bot identity for every agent without a refresh.
        broadcast(new \App\Events\ConversationUpdated($conversation->fresh('bot:id,name')));

        return response()->json([
            'message' => 'Bot updated successfully',
            'conversation' => $conversation->fresh('bot:id,name'),
        ]);
    }
}
