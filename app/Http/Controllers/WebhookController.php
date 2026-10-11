<?php

namespace App\Http\Controllers;

use App\Models\Channel;
use App\Services\BusinessHoursService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class WebhookController extends Controller
{
    public function verify(Request $request)
    {
        $verifyToken = config('services.meta.webhook_verify_token');

        if (
            $request->get('hub_mode') === 'subscribe' &&
            $request->get('hub_verify_token') === $verifyToken
        ) {
            return response($request->get('hub_challenge'), 200);
        }

        return response('Forbidden', 403);
    }

    public function handle(Request $request)
    {
        try {
            // Verify webhook signature for security.
            //
            // The HMAC must be computed over the EXACT raw request body.
            // $request->getContent() returns the untouched php://input stream
            // — Laravel's TrimStrings / ConvertEmptyStringsToNull middleware
            // mutate only the parsed parameter bags ($request->query/json/
            // request), never the raw body, so this value is safe to sign.
            // (Historical note: a "soft mode" here blamed middleware mutation
            // for signature mismatches — that premise was wrong; see
            // Illuminate\Foundation\Http\Middleware\TransformsRequest::clean().)
            $signature = trim((string) $request->header('X-Hub-Signature-256', ''));
            $payload = $request->getContent();
            $appSecret = config('services.meta.app_secret');

            if (!$appSecret) {
                Log::critical('Meta webhook rejected: app secret is not configured');
                return response('Webhook signature verification is unavailable', 503);
            }

            if ($appSecret) {
                if ($signature === '') {
                    // Meta always signs webhook deliveries; a payload without
                    // a signature while a secret is configured is not ours.
                    Log::warning('Meta webhook rejected: missing X-Hub-Signature-256 header');
                    return response('Invalid signature', 403);
                }

                $expectedSignature = 'sha256=' . hash_hmac('sha256', $payload, $appSecret);

                if (!hash_equals($expectedSignature, $signature)) {
                    app(\App\Support\OperationalMetricCounter::class)->increment('webhook_signature_failures');
                    Log::warning('Invalid Meta webhook signature', [
                        'payload_length' => strlen($payload),
                    ]);
                    return response('Invalid signature', 403);
                }

                Log::info('Meta webhook signature verified successfully');
            }

            $body = $request->all();
            $object = $body['object'] ?? '';
            Log::info('Meta Webhook received', [
                'object' => $object,
                'entry_count' => count($body['entry'] ?? []),
            ]);

            if ($object === 'instagram') {
                $this->handleInstagram($body);
            } elseif ($object === 'whatsapp_business_account') {
                $this->handleWhatsAppCloud($body);
            } elseif ($object === 'page') {
                // Meta sends Instagram DMs as object=page when using Messenger API for Instagram.
                // Check if any entry ID matches a connected Instagram account — if so, route to Instagram handler.
                $igIds = \App\Models\Channel::where('type', 'instagram')
                    ->where('status', 'connected')
                    ->get(['instagram_account_id', 'page_id'])
                    ->flatMap(fn($c) => array_filter([$c->instagram_account_id]))
                    ->toArray();

                $hasInstagramEntry = false;
                foreach ($body['entry'] ?? [] as $entry) {
                    if (in_array($entry['id'], $igIds, true)) {
                        $hasInstagramEntry = true;
                        break;
                    }
                }

                if ($hasInstagramEntry) {
                    $this->handleInstagram($body);
                } else {
                    $this->handleFacebook($body);
                }
            }

            return response('EVENT_RECEIVED', 200);
        } catch (\Exception $e) {
            Log::error('Webhook handler error', ['exception' => get_class($e)]);
            return response('EVENT_RECEIVED', 200);
        }
    }

    /** Route Cloud API messages by their exact Meta phone-number asset ID. */
    private function handleWhatsAppCloud(array $body): void
    {
        foreach ($body['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                if (($change['field'] ?? null) !== 'messages') {
                    continue; // Delivery/read status notifications are not inbound messages.
                }

                $value = $change['value'] ?? [];
                $phoneNumberId = (string) ($value['metadata']['phone_number_id'] ?? '');
                if ($phoneNumberId === '') {
                    Log::warning('WhatsApp Cloud webhook missing phone number ID');
                    continue;
                }

                $channel = Channel::query()
                    ->where('type', 'whatsapp')
                    ->where('page_id', $phoneNumberId)
                    ->where('status', 'connected')
                    ->whereJsonContains('metadata->whatsapp_provider', 'cloud')
                    ->first();

                if (!$channel) {
                    Log::warning('WhatsApp Cloud webhook has no matching connected channel', [
                        'phone_number_id' => $phoneNumberId,
                    ]);
                    continue;
                }

                foreach ($value['statuses'] ?? [] as $statusEvent) {
                    $providerMessageId = $statusEvent['id'] ?? null;
                    $providerStatus = strtolower((string) ($statusEvent['status'] ?? ''));
                    if (!$providerMessageId || !in_array($providerStatus, ['sent', 'delivered', 'read', 'failed'], true)) {
                        continue;
                    }

                    $message = \App\Models\Message::where('whatsapp_message_id', $providerMessageId)
                        ->whereHas('conversation', fn ($query) => $query->where('channel_id', $channel->id))
                        ->first();
                    if (!$message) {
                        continue;
                    }

                    // Meta may retry or reorder delivery callbacks. Preserve a
                    // terminal/read state when an older event arrives later.
                    $rank = ['pending' => 0, 'queued' => 0, 'sent' => 1, 'delivered' => 2, 'read' => 3, 'failed' => 4, 'blocked' => 4];
                    $currentStatus = (string) ($message->send_status ?: 'queued');
                    if (in_array($currentStatus, ['failed', 'blocked'], true)
                        || ($currentStatus === 'read' && $providerStatus !== 'read')
                        || ($rank[$providerStatus] ?? -1) < ($rank[$currentStatus] ?? -1)) {
                        continue;
                    }

                    $message->update([
                        'status' => $providerStatus,
                        'send_status' => $providerStatus,
                        // The legacy enum predates `read`; keep it at delivered
                        // while send_status retains Meta's exact terminal state.
                        'delivery_status' => $providerStatus === 'read' ? 'delivered' : $providerStatus,
                        'error_details' => $providerStatus === 'failed'
                            ? $this->whatsAppStatusFailureReason($statusEvent)
                            : null,
                    ]);
                }

                foreach ($value['messages'] ?? [] as $incoming) {
                    $senderId = (string) ($incoming['from'] ?? '');
                    $messageId = $incoming['id'] ?? null;
                    $text = $this->whatsAppCloudMessageText($incoming);
                    if ($senderId === '') {
                        continue;
                    }

                    $media = null;
                    $incomingType = (string) ($incoming['type'] ?? '');
                    if (in_array($incomingType, ['image', 'audio', 'video', 'document'], true)) {
                        $mediaId = (string) ($incoming[$incomingType]['id'] ?? '');
                        $media = $mediaId !== ''
                            ? app(\App\Services\WhatsAppCloudApiService::class)->downloadInboundMedia($channel, $mediaId, $incomingType)
                            : ['type' => $incomingType, 'storage_status' => 'failed'];
                    }
                    $attachmentContext = in_array($incomingType, ['image', 'audio', 'video', 'document', 'location'], true)
                        ? [
                            'type' => $incomingType,
                            'provider_message_id' => $messageId,
                            'provider_attachment_id' => $incoming[$incomingType]['id'] ?? null,
                            'status' => $text === '' ? 'attachment_without_text' : 'received_with_text',
                            'coordinates' => $incomingType === 'location'
                                && is_numeric($incoming['location']['latitude'] ?? null)
                                && is_numeric($incoming['location']['longitude'] ?? null)
                                    ? ['lat' => (float) $incoming['location']['latitude'], 'lng' => (float) $incoming['location']['longitude']]
                                    : null,
                        ]
                        : null;
                    if ($text === '' && $media === null && $attachmentContext === null) {
                        continue;
                    }

                    $senderName = collect($value['contacts'] ?? [])->firstWhere('wa_id', $senderId)['profile']['name'] ?? null;
                    $this->processMessage($channel, $senderId, $text, $messageId, app(\App\Services\ReferencedMessageNormalizer::class)->whatsappCloud($incoming), $senderName, $media, $attachmentContext);
                }
            }
        }
    }

    private function whatsAppStatusFailureReason(array $statusEvent): string
    {
        $error = $statusEvent['errors'][0] ?? [];
        $reason = $error['title'] ?? $error['message'] ?? $error['details'] ?? $error['code'] ?? 'WhatsApp delivery failed';
        return mb_substr(trim((string) $reason), 0, 2000) ?: 'WhatsApp delivery failed';
    }

    private function whatsAppCloudMessageText(array $message): string
    {
        return match ($message['type'] ?? '') {
            'text' => trim((string) ($message['text']['body'] ?? '')),
            'button' => trim((string) ($message['button']['text'] ?? '')),
            'interactive' => trim((string) (
                $message['interactive']['button_reply']['title']
                ?? $message['interactive']['list_reply']['title']
                ?? ''
            )),
            'location' => '',
            'image' => trim((string) ($message['image']['caption'] ?? '')),
            'document' => trim((string) ($message['document']['caption'] ?? '')),
            'audio' => '',
            'video' => trim((string) ($message['video']['caption'] ?? '')),
            default => '',
        };
    }

    private function handleFacebook(array $body): void
    {
        foreach ($body['entry'] as $entry) {
            $pageId = $entry['id'];

            if (str_starts_with($pageId, 'TEST_')) continue;

            // Handle messages (existing functionality)
            foreach ($entry['messaging'] ?? [] as $event) {
                if (isset($event['read'])) {
                    $this->applyMetaReadReceipt($event, 'facebook', (string) $pageId);
                    continue;
                }
                if (isset($event['message']['is_echo'])) continue;
                $incoming = $event['message'] ?? [];
                $attachments = $incoming['attachments'] ?? [];
                if (!isset($incoming['text']) && empty($attachments)) continue;

                $senderId    = $event['sender']['id'];
                $recipientId = $event['recipient']['id'] ?? $pageId;
                $messageText = trim((string) ($incoming['text'] ?? ''));

                if (str_starts_with($senderId, 'TEST_')) continue;

                $channel = Channel::where('page_id', $recipientId)
                    ->where('type', 'facebook')
                    ->where('status', 'connected')
                    ->latest('connected_at')
                    ->first();

                if (!$channel) {
                    Log::warning('No Facebook channel found for page_id: ' . $recipientId);
                    continue;
                }

                $media = $this->downloadMetaAttachment($this->metaAttachmentDescriptor($attachments));
                $attachmentContext = $this->metaAttachmentContext($attachments, $incoming['mid'] ?? null, $messageText === '');
                if ($messageText === '' && $media === null && $attachmentContext === null) continue;
                $this->processMessage($channel, $senderId, $messageText, $incoming['mid'] ?? null, app(\App\Services\ReferencedMessageNormalizer::class)->meta($incoming), null, $media, $attachmentContext);
            }

            // Handle comments
            foreach ($entry['changes'] ?? [] as $change) {
                if ($change['field'] !== 'comments') continue;

                $comment = $change['value'];
                $commentText = $comment['text'] ?? '';
                $commentId = $comment['comment_id'] ?? '';
                $senderId = $comment['from']['id'] ?? '';
                $parentId = $comment['parent_id'] ?? ''; // For replies to comments

                // Skip if no text or if it's from our own page (to prevent loops)
                if (empty($commentText) || empty($senderId)) continue;

                // Get the page ID from the entry
                $pageIdFromEntry = $entry['id'];

                // Skip if comment is from our own page (prevent self-reply loops)
                if ($senderId === $pageIdFromEntry) continue;

                $channel = Channel::where('page_id', $pageIdFromEntry)
                    ->where('type', 'facebook')
                    ->where('status', 'connected')
                    ->latest('connected_at')
                    ->first();

                if (!$channel) {
                    Log::warning('No Facebook channel found for page_id: ' . $pageIdFromEntry);
                    continue;
                }

                // For comments, we need to determine if this is a top-level comment or a reply
                // For now, we'll treat all comments similarly, but we could enhance this later
                $this->processComment($channel, $senderId, $commentText, $commentId);
            }
        }
    }

    private function handleInstagram(array $body): void
    {
        foreach ($body['entry'] as $entry) {
            $entryId = $entry['id']; // Page ID when object=page, IG account ID when object=instagram

            if (str_starts_with($entryId, 'TEST_')) continue;

            Log::info('Instagram entry', ['id' => $entryId, 'keys' => array_keys($entry)]);

            foreach ($entry['messaging'] ?? [] as $event) {
                if (isset($event['read'])) {
                    $this->applyMetaReadReceipt($event, 'instagram', (string) $entryId);
                    continue;
                }
                $incomingMessage = $event['message'] ?? [];
                if (isset($incomingMessage['is_echo'])) continue;
                if (isset($event['message_edit'])) continue;

                $location = $this->extractMetaLocation($incomingMessage);
                $messageText = trim((string) ($incomingMessage['text'] ?? ''));
                $attachments = $incomingMessage['attachments'] ?? [];
                $attachmentDescriptor = $this->metaAttachmentDescriptor($attachments);
                if (!$location && $messageText === '' && empty($attachments)) {
                    continue;
                }

                $senderId    = $event['sender']['id'];
                $platformMessageId = $incomingMessage['mid'] ?? null;
                $replyToMid = app(\App\Services\ReferencedMessageNormalizer::class)->meta($incomingMessage);

                if (str_starts_with($senderId, 'TEST_')) continue;

                Log::info('Instagram DM received', [
                    'entry_id' => $entryId,
                    'has_platform_message_id' => $platformMessageId !== null,
                    'message_length' => mb_strlen($messageText),
                ]);

                // Strategy 1: entry ID is the Instagram account ID (object=instagram)
                $channel = Channel::where('instagram_account_id', $entryId)
                    ->where('type', 'instagram')
                    ->where('status', 'connected')
                    ->latest('connected_at')
                    ->first();

                  // Instagram rows are routed only by instagram_account_id. There is no page_id or
                  // same-user guessing fallback: an unmatched event is logged and skipped below.
                if (!$channel) {
                    Log::error('No Instagram channel found', [
                        'entry_id'  => $entryId,
                        'available_channel_count' => Channel::where('type', 'instagram')
                            ->where('status', 'connected')->count(),
                    ]);
                    continue;
                }

                $media = $location ? null : $this->downloadMetaAttachment($attachmentDescriptor);
                $attachmentContext = $this->metaAttachmentContext($attachments, $platformMessageId, $messageText === '', $location);
                if ($messageText === '' && $media === null && $attachmentContext === null) continue;
                $this->processMessage($channel, $senderId, $messageText, $platformMessageId, $replyToMid, null, $media, $attachmentContext);
            }

            // Handle Instagram comments
            foreach ($entry['changes'] ?? [] as $change) {
                if ($change['field'] !== 'comments') continue;

                $comment     = $change['value'];
                $commentText = $comment['text'] ?? '';
                $commentId   = $comment['comment_id'] ?? '';
                $senderId    = $comment['from']['id'] ?? '';

                if (empty($commentText) || empty($senderId)) continue;
                if ($senderId === $entryId) continue;

                $channel = Channel::where('instagram_account_id', $entryId)
                    ->where('type', 'instagram')->where('status', 'connected')
                    ->latest('connected_at')->first();

                // Comments are routed only by instagram_account_id (no page_id or same-user fallback).

                if (!$channel) {
                    Log::warning('No Instagram channel found for comment', ['entry_id' => $entryId]);
                    continue;
                }

                $this->processComment($channel, $senderId, $commentText, $commentId);
            }
        }
    }

    /** Apply a Meta read watermark to outbound messages on the matching asset. */
    private function applyMetaReadReceipt(array $event, string $platform, string $entryId): void
    {
        $assetIds = array_unique(array_filter([
            $entryId,
            (string) ($event['recipient']['id'] ?? ''),
        ]));
        $channelQuery = Channel::query()->where('status', 'connected');
        if ($platform === 'instagram') {
            $channelQuery->where('type', 'instagram')->where(function ($query) use ($assetIds) {
                $query->whereIn('instagram_account_id', $assetIds)->orWhereIn('page_id', $assetIds);
            });
        } else {
            $channelQuery->where('type', 'facebook')->whereIn('page_id', $assetIds);
        }
        $channel = $channelQuery->first();
        if (!$channel) {
            return;
        }

        $watermark = $event['read']['watermark'] ?? null;
        if (!is_numeric($watermark)) {
            return;
        }

        $readThrough = \Illuminate\Support\Carbon::createFromTimestampMs((int) $watermark);
        $conversationIds = \App\Models\Conversation::where('channel_id', $channel->id)->select('id');
        $messages = \App\Models\Message::whereIn('conversation_id', $conversationIds)
            ->where('direction', 'outbound')
            ->where('created_at', '<=', $readThrough)
            ->whereIn('send_status', ['queued', 'pending', 'sent', 'delivered', 'read'])
            ->get();

        foreach ($messages as $message) {
            // A read watermark must never downgrade a delivery state.
            if (in_array($message->send_status, ['queued', 'pending', 'sent', 'delivered'], true)) {
                $message->update([
                    'status' => 'read',
                    'send_status' => 'read',
                    // `delivery_status` predates Meta's read state and its
                    // database enum ends at delivered; send_status is canonical.
                    'delivery_status' => 'delivered',
                ]);
            }
        }
    }

    private function processMessage(Channel $channel, string $senderId, string $messageText, ?string $platformMessageId = null, ?string $replyToMid = null, ?string $senderName = null, ?array $media = null, ?array $attachmentContext = null): void
    {
        Log::info('Processing message', [
            'channel_type' => $channel->type,
            'channel_id'   => $channel->id,
            'business_id'  => $channel->business_id,
            'has_platform_message_id' => $platformMessageId !== null,
            'message_length' => mb_strlen($messageText),
        ]);

        // ── WEBHOOK IDEMPOTENCY ────────────────────────────────────────────
        // Use platform message ID to detect duplicate webhook deliveries.
        // Same ID arriving twice = duplicate, skip it.
        // Different IDs = different customer messages, always process.
        if ($platformMessageId) {
            $idempotencyKey = "webhook:{$channel->type}:{$platformMessageId}";
            if ($this->safeCacheHas($idempotencyKey)) {
                Log::info('Duplicate webhook delivery — skipping', [
                    'channel_type' => $channel->type,
                    'has_platform_message_id' => true,
                ]);
                return;
            }
            $this->safeCachePut($idempotencyKey, true, now()->addMinutes(5));
        }

        $conversation = \App\Models\Conversation::firstOrCreate(
            ['channel_id' => $channel->id, 'sender_id' => $senderId],
            ['business_id' => $channel->business_id, 'status' => 'open', 'last_message_at' => now(), 'sender_name' => $senderName]
        );
        if ($senderName && empty($conversation->sender_name)) {
            $conversation->update(['sender_name' => $senderName]);
        }

        // Resolve/link the business-scoped Customer for this sender (Instagram/
        // Facebook sender ids have no phone/email — platform-identity matching).
        try {
            app(\App\Services\CustomerService::class)->attachToConversation($conversation);
        } catch (\Throwable $e) {
            Log::warning('Customer resolution failed for Meta sender', [
                'conversation_id' => $conversation->id,
                'exception'       => get_class($e),
            ]);
        }

        // If we don't have a name for this sender yet, queue a job to fetch it from the Graph API
        if ($channel->type !== 'whatsapp' && ($conversation->wasRecentlyCreated || empty($conversation->sender_name))) {
            \App\Jobs\FetchSenderName::dispatch($conversation->id, $channel->id, $senderId);
        }

        $conversation->last_message_at = now();
        $conversation->save();

        // Build metadata with reply_to information for product resolution
        $metadata = [];
        if ($platformMessageId) {
            $metadata['platform_message_id'] = $platformMessageId;
        }
        if ($replyToMid) {
            $metadata['referenced_platform_message_id'] = $replyToMid;
            $metadata['quoted_message_id'] = $replyToMid;
            $metadata['reply_to_platform'] = $channel->type;
        }
        if ($media !== null) {
            $metadata['media'] = $media;
        }
        if ($attachmentContext !== null) {
            $metadata['attachment'] = $attachmentContext;
        }

        $message = \App\Models\Message::create([
            'conversation_id' => $conversation->id,
            'content'         => $messageText,
            'type'            => $media['type'] ?? 'text',
            'direction'       => 'inbound',
            'is_ai'           => false,
            'status'          => 'received',
            'media_url'       => $media['url'] ?? null,
            'media_type'      => $media['type'] ?? null,
            'mime_type'       => $media['mime_type'] ?? null,
            'file_size'       => $media['file_size'] ?? null,
            'media_processing_status' => $media === null
                ? (($attachmentContext['status'] ?? null) === 'attachment_without_text' ? 'attachment_without_text' : null)
                : (($media['storage_status'] ?? null) === 'stored' ? 'pending' : 'failed'),
            'media_processing_reason' => $media !== null && ($media['storage_status'] ?? null) !== 'stored'
                ? 'Media download or private storage failed.'
                : null,
            'metadata'         => !empty($metadata) ? $metadata : null,
            'whatsapp_message_id' => $channel->type === 'whatsapp' ? $platformMessageId : null,
            'whatsapp_remote_jid' => $channel->type === 'whatsapp' ? $senderId : null,
            'whatsapp_from_me' => false,
        ]);

        if ($attachmentContext !== null) {
            Log::info('Attachment handled', [
                'message_id' => $message->id,
                'attachment_type' => $attachmentContext['type'] ?? 'unknown',
                'status' => $attachmentContext['status'] ?? 'received',
            ]);
        }

        if ($channel->user_id) {
            broadcast(new \App\Events\MessageReceived($message, $conversation, $channel->user_id));
        }

        // ── DEBOUNCE FIX ───────────────────────────────────────────────────
        // Only debounce if the SAME message is being processed again (retry/duplicate).
        // Different customer messages are always dispatched — they will be handled
        // sequentially by the queue worker.
        $debounceKey = "debounce:message:{$message->id}";
        if ($this->safeCacheHas($debounceKey)) {
            Log::info('Message already being processed — skipping duplicate job', [
                'conversation_id' => $conversation->id,
                'message_id' => $message->id
            ]);
        } else {
            \App\Jobs\ProcessAutoReply::dispatch($message->id);
            $this->safeCachePut($debounceKey, true, 30); // 30s — only prevents duplicate job dispatch
            Log::info('ProcessAutoReply job dispatched', ['message_id' => $message->id]);
        }
    }

    /** Read Instagram/Messenger location attachment coordinates into ordinary message text. */
    private function extractMetaLocation(array $message): ?array
    {
        foreach (($message['attachments'] ?? []) as $attachment) {
            $coordinates = $this->findMetaCoordinates($attachment);
            if ($coordinates) {
                return $coordinates;
            }
        }

        return null;
    }

    private function metaAttachmentDescriptor(array $attachments): ?array
    {
        foreach ($attachments as $attachment) {
            if (!is_array($attachment)) continue;
            $type = strtolower((string) ($attachment['type'] ?? ''));
            $payload = $attachment['payload'] ?? [];
            $url = $payload['url'] ?? $attachment['url'] ?? null;
            if (!is_string($url) || $url === '') continue;
            $normalizedType = match ($type) {
                'image' => 'image', 'audio' => 'audio', 'video' => 'video',
                'file' => 'document', default => null,
            };
            if ($normalizedType === null) continue;
            return [
                'type' => $normalizedType,
                'url' => $url,
                'mime_type' => is_string($payload['mime_type'] ?? null) ? $payload['mime_type'] : null,
                'file_name' => is_string($payload['name'] ?? null) ? $payload['name'] : null,
            ];
        }
        return null;
    }

    /** Keep provider attachment facts separate from customer-authored text. */
    private function metaAttachmentContext(array $attachments, ?string $messageId, bool $withoutText, ?array $coordinates = null): ?array
    {
        if ($attachments === []) {
            return null;
        }

        $attachment = $attachments[0] ?? [];
        if (!is_array($attachment)) {
            $attachment = [];
        }
        $payload = is_array($attachment['payload'] ?? null) ? $attachment['payload'] : [];
        $type = strtolower((string) ($attachment['type'] ?? 'unknown'));

        return [
            'type' => $type !== '' ? $type : 'unknown',
            'provider_message_id' => $messageId,
            'provider_attachment_id' => $payload['attachment_id'] ?? $attachment['attachment_id'] ?? null,
            'status' => $withoutText ? 'attachment_without_text' : 'received_with_text',
            'coordinates' => $coordinates,
        ];
    }

    /** Fetch only HTTPS media from Meta CDN hosts, then store it privately. */
    private function downloadMetaAttachment(?array $attachment): ?array
    {
        if ($attachment === null) return null;
        $failed = [
            'type' => $attachment['type'],
            'storage_status' => 'failed',
            'mime_type' => $attachment['mime_type'] ?? null,
            'file_name' => $attachment['file_name'] ?? null,
        ];
        try {
            $url = $attachment['url'];
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));
            $trustedHost = $host === 'lookaside.fbsbx.com'
                || str_ends_with($host, '.fbcdn.net')
                || str_ends_with($host, '.fbsbx.com');
            if (strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https' || !$trustedHost) {
                return $failed;
            }

            $download = Http::timeout(30)->withoutRedirecting()->get($url);
            if (!$download->successful() || strlen($download->body()) > 50 * 1024 * 1024) {
                return $failed;
            }
            $mime = strtolower(trim(explode(';', (string) ($attachment['mime_type'] ?? $download->header('Content-Type', 'application/octet-stream')))[0]));
            $extension = match ($mime) {
                'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp',
                'audio/ogg' => 'ogg', 'audio/mpeg' => 'mp3', 'audio/mp4' => 'm4a',
                'video/mp4' => 'mp4', 'application/pdf' => 'pdf',
                default => strtolower(pathinfo($attachment['file_name'] ?? parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION)) ?: 'bin',
            };
            $storage = app(\App\Services\IncomingMediaStorage::class);
            $contents = $download->body();
            $path = $storage->store($contents, $extension);

            return [
                'type' => $attachment['type'], 'url' => $path,
                'storage_disk' => $storage->diskName(), 'storage_status' => 'stored',
                'mime_type' => $mime, 'file_name' => $attachment['file_name'] ?? null,
                'file_size' => strlen($contents),
            ];
        } catch (\Throwable $exception) {
            Log::warning('Meta inbound media could not be stored', [
                'media_type' => $attachment['type'],
                'error_class' => get_class($exception),
            ]);
            return $failed;
        }
    }

    /** Search nested template/location payloads without logging customer coordinates. */
    private function findMetaCoordinates(mixed $data, int $depth = 0): ?array
    {
        // The webhook log normalizer truncates at depth 9, so template payloads
        // can carry valid coordinates deeper than the original shallow search.
        if (!is_array($data) || $depth > 32) {
            return null;
        }

        $latitude = $data['lat'] ?? $data['latitude'] ?? null;
        $longitude = $data['long'] ?? $data['lng'] ?? $data['longitude'] ?? null;
        if (is_numeric($latitude) && is_numeric($longitude)) {
            $latitude = (float) $latitude;
            $longitude = (float) $longitude;
            if ($latitude >= -90 && $latitude <= 90 && $longitude >= -180 && $longitude <= 180) {
                return ['lat' => $latitude, 'lng' => $longitude];
            }
        }

        foreach ($data as $value) {
            $coordinates = $this->findMetaCoordinates($value, $depth + 1);
            if ($coordinates) {
                return $coordinates;
            }
        }

        return null;
    }

    /** Return attachment keys and value types only; never log location values. */
    private function summarizeMetaAttachmentStructure(array $attachments): array
    {
        $paths = [];
        $visit = function (mixed $value, string $path, int $depth) use (&$visit, &$paths): void {
            if (!is_array($value) || $depth > 32 || count($paths) >= 120) {
                return;
            }

            foreach ($value as $key => $child) {
                if (count($paths) >= 120) {
                    return;
                }

                $childPath = $path . '.' . (string) $key;
                $paths[] = $childPath . ' (' . (is_array($child) ? 'array' : get_debug_type($child)) . ')';
                if (is_array($child)) {
                    $visit($child, $childPath, $depth + 1);
                }
            }
        };

        $visit($attachments, 'attachments', 0);

        return $paths;
    }

    /** File-cache errors must not discard a verified customer webhook. */
    private function safeCacheHas(string $key): bool
    {
        try {
            return Cache::has($key);
        } catch (\Throwable $e) {
            Log::warning('Webhook cache read failed; continuing without cache', [
                'key' => $key,
                'exception_type' => get_class($e),
            ]);
            return false;
        }
    }

    private function safeCachePut(string $key, mixed $value, mixed $ttl): void
    {
        try {
            Cache::put($key, $value, $ttl);
        } catch (\Throwable $e) {
            Log::warning('Webhook cache write failed; continuing with message processing', [
                'key' => $key,
                'exception_type' => get_class($e),
            ]);
        }
    }

    private function sendReply(Channel $channel, string $recipientId, string $message): void
    {
        // The Channel model's accessor already decrypts access_token — do NOT
        // call decrypt() again or it will throw "The payload is invalid."
        $accessToken = $channel->access_token;
        
        // /me/messages works for both Facebook and Instagram when using the Page Access Token
        $url = "https://graph.facebook.com/v19.0/me/messages?access_token={$accessToken}";

        try {
            $response = Http::timeout(10)
                ->post($url, [
                    'recipient' => ['id' => $recipientId],
                    'message'   => ['text' => $message],
                ]);

            if ($response->successful()) {
                Log::info('Reply sent successfully via ' . $channel->type, ['recipient_present' => $recipientId !== '']);
            } else {
                Log::error('Failed to send reply', [
                    'channel_type' => $channel->type,
                    'status'       => $response->status(),
                    'status' => $response->status(),
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Send reply exception', ['exception_type' => get_class($e)]);
        }
    }

    private function buildPrompt(string $messageText, Channel $channel): string
    {
        $business = $channel->business;

        if (!$business) {
            return "You are a helpful customer service assistant. Reply to this message politely in the same language the customer used.\n\nCustomer message: {$messageText}\n\nReply:";
        }

        $workingDays  = is_array($business->working_days) ? implode(', ', $business->working_days) : ($business->working_days ?? 'N/A');
        $workingHours = "{$workingDays} from {$business->working_from} to {$business->working_to}";

        $faqsText = '';
        if (!empty($business->faqs)) {
            $faqs = is_array($business->faqs) ? $business->faqs : json_decode($business->faqs, true);
            if (is_array($faqs)) {
                foreach ($faqs as $faq) {
                    $q = $faq['question'] ?? $faq['q'] ?? '';
                    $a = $faq['answer']   ?? $faq['a'] ?? '';
                    if ($q && $a) $faqsText .= "Q: {$q}\nA: {$a}\n";
                }
            }
        }

        return "You are a customer service assistant for the following business. Never say you are an AI.

Business name: {$business->business_name}
Business type: {$business->business_type}
Location: {$business->city}, {$business->country}
Working hours: {$workingHours}
Services/Products: {$business->services}
Reply style: " . ($business->reply_style ?? 'friendly and professional') . "

" . ($faqsText ? "Common questions and answers:\n{$faqsText}\n" : '') . "
Rules:
- Only use the information provided above. Never make things up.
- If you don't know the answer, politely say you will follow up and ask for their contact.
- Reply in the same language the customer used (Arabic or English).
- Keep replies short, clear, and friendly.
- Never mention you are an AI or a bot.

Customer message: {$messageText}

Reply:";
    }

    private function getGeminiReply(string $messageText, Channel $channel): ?string
    {
        $apiKey = env('GEMINI_API_KEY');
        $model  = env('GEMINI_MODEL', 'gemini-2.5-flash');
        $prompt = $this->buildPrompt($messageText, $channel);

        try {
            $response = Http::timeout(20)
                ->post(
                    "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}",
                    [
                        'contents'         => [['parts' => [['text' => $prompt]]]],
                        'generationConfig' => ['maxOutputTokens' => 300, 'temperature' => 0.7],
                    ]
                );

            if (!$response->successful()) {
                Log::error('Gemini API error', ['http_status' => $response->status()]);
                return null;
            }

            $reply = $response->json()['candidates'][0]['content']['parts'][0]['text'] ?? null;
            return $reply ? trim($reply) : null;

        } catch (\Exception $e) {
            Log::error('Gemini exception', ['exception_type' => get_class($e)]);
            return null;
        }
    }

    /*
     * Process a comment from Facebook or Instagram
     * Similar to processMessage but for comments instead of direct messages
     */
    private function processComment(Channel $channel, string $senderId, string $commentText, string $commentId): void
    {
        Log::info('Processing comment', [
            'channel_type' => $channel->type,
            'channel_id'   => $channel->id,
            'sender'       => $senderId,
            'comment'      => $commentText,
            'comment_id'   => $commentId,
            'business_id'  => $channel->business_id,
        ]);

        // Check if we've already processed this comment (idempotency)
        $processedKey = "processed_comment:{$commentId}";
        if (Cache::has($processedKey)) {
            Log::info('Comment already processed, skipping', [
                'comment_id' => $commentId,
                'channel_id' => $channel->id
            ]);
            return;
        }

        // Mark this comment as processed for 24 hours to prevent duplicates
        Cache::put($processedKey, true, 60 * 60 * 24); // 24 hours

        $conversation = \App\Models\Conversation::firstOrCreate(
            ['channel_id' => $channel->id, 'sender_id' => $senderId],
            ['business_id' => $channel->business_id, 'status' => 'open', 'last_message_at' => now()]
        );

        // Resolve/link the business-scoped Customer for this sender (Instagram/
        // Facebook sender ids have no phone/email — platform-identity matching).
        try {
            app(\App\Services\CustomerService::class)->attachToConversation($conversation);
        } catch (\Throwable $e) {
            Log::warning('Customer resolution failed for Meta sender', [
                'conversation_id' => $conversation->id,
                'exception'       => get_class($e),
            ]);
        }

        // If we don't have a name for this sender yet, queue a job to fetch it from the Graph API
        if ($conversation->wasRecentlyCreated || empty($conversation->sender_name)) {
            \App\Jobs\FetchSenderName::dispatch($conversation->id, $channel->id, $senderId);
        }

        $conversation->last_message_at = now();
        $conversation->save();

        $message = \App\Models\Message::create([
            'conversation_id' => $conversation->id,
            'content'         => $commentText,
            'direction'       => 'inbound',
            'is_ai'           => false,
            'status'          => 'received',
            // Store the comment ID for reference/idempotency
            'metadata' => [
                'comment_id' => $commentId,
                'platform'   => $channel->type === 'facebook' ? 'facebook' : 'instagram'
            ]
        ]);

        if ($channel->user_id) {
            broadcast(new \App\Events\MessageReceived($message, $conversation, $channel->user_id));
        }

        // Implement comment debounce to prevent multiple AI replies to the same conversation
        $debounceKey = "debounce:conversation:{$conversation->id}";
        $debounceWindow = 10; // 10 seconds debounce window

        if (Cache::has($debounceKey)) {
            Log::info('Comment debounced - AI reply skipped', [
                'conversation_id' => $conversation->id,
                'message_id' => $message->id
            ]);
        } else {
            \App\Jobs\ProcessAutoReply::dispatch($message->id);
            Cache::put($debounceKey, true, $debounceWindow);
            Log::info('ProcessAutoReply job dispatched for comment', ['message_id' => $message->id]);
        }
    }

    public function handleGmail(Request $request)
    {
        try {
            $body = $request->all();
            Log::info('Gmail Pub/Sub webhook received', [
                'has_message' => isset($body['message']),
                'message_id' => is_string($body['message']['messageId'] ?? null) ? $body['message']['messageId'] : null,
            ]);

            // Google Pub/Sub sends the message in a specific format
            $messageData = $body['message'] ?? null;
            if (!$messageData) {
                Log::warning('Gmail webhook: no message data');
                return response('OK', 200);
            }

            // Decode the base64-encoded Pub/Sub message
            $encodedData = $messageData['data'] ?? null;
            if (!$encodedData) {
                Log::warning('Gmail webhook: no data in message');
                return response('OK', 200);
            }

            $decoded = base64_decode($encodedData);
            $payload = json_decode($decoded, true);

            $emailAddress = $payload['emailAddress'] ?? null;
            $historyId = $payload['historyId'] ?? null;

            if (!$emailAddress || !$historyId) {
                Log::warning('Gmail webhook: missing emailAddress or historyId', [
                    'has_email_address' => (bool) $emailAddress,
                    'has_history_id' => (bool) $historyId,
                ]);
                return response('OK', 200);
            }

            Log::info('Gmail push notification', [
                'has_email_address' => true,
                'history_id' => $historyId,
            ]);

            // Find the channel by email address
            $channel = Channel::where('type', 'gmail')
                ->where('page_name', $emailAddress)
                ->where('status', 'connected')
                ->first();

            if (!$channel) {
                Log::warning('Gmail webhook: no channel found for email');
                return response('OK', 200);
            }

            // Get the last history ID from the channel
            $lastHistoryId = $channel->gmail_history_id;
            if (!$lastHistoryId) {
                Log::warning('Gmail webhook: no history_id on channel', ['channel_id' => $channel->id]);
                return response('OK', 200);
            }

            // Fetch history from Gmail API
            $gmailCtrl = new GmailController();
            $client = $gmailCtrl->getAuthenticatedClient($channel);
            if (!$client) {
                Log::error('Gmail webhook: could not get authenticated client', ['channel_id' => $channel->id]);
                return response('OK', 200);
            }

            $gmail = new \Google\Service\Gmail($client);

            // Fetch history since last history ID
            $historyResponse = $gmail->users_history->listUsersHistory('me', [
                'startHistoryId' => $lastHistoryId,
                'historyTypes' => 'messageAdded',
                'labelId' => 'INBOX',
            ]);

            $histories = $historyResponse->getHistory();
            $latestHistoryId = $historyId;

            if ($histories) {
                foreach ($histories as $historyRecord) {
                    $messagesAdded = $historyRecord->getMessagesAdded();
                    if ($messagesAdded) {
                        foreach ($messagesAdded as $messageAdded) {
                            $messageId = $messageAdded->getMessage()->getId();
                            $this->processGmailMessage($channel, $gmail, $messageId);
                        }
                    }
                }
            }

            // Update the channel's history ID to the latest
            $channel->update(['gmail_history_id' => $latestHistoryId]);

            Log::info('Gmail webhook processed', [
                'channel_id' => $channel->id,
                'latest_history_id' => $latestHistoryId,
            ]);

            return response('OK', 200);

        } catch (\Exception $e) {
            Log::error('Gmail webhook error', [
                'exception' => get_class($e),
            ]);
            return response('OK', 200); // Always return 200 to avoid retries
        }
    }

    private function processGmailMessage(Channel $channel, \Google\Service\Gmail $gmail, string $messageId): void
    {
        try {
            $message = $gmail->users_messages->get('me', $messageId, ['format' => 'full']);
            $payload = $message->getPayload();

            // Extract headers
            $headers = [];
            foreach ($payload->getHeaders() as $header) {
                $headers[$header->getName()] = $header->getValue();
            }

            $from = $headers['From'] ?? '';
            $subject = $headers['Subject'] ?? '';
            $threadId = $message->getThreadId();
            $gmailInReplyTo = app(\App\Services\ReferencedMessageNormalizer::class)
                ->gmail($headers['In-Reply-To'] ?? null);

            // Parse sender email
            preg_match('/<(.+)>/', $from, $matches);
            $senderEmail = $matches[1] ?? $from;
            $senderName = trim(str_replace(['<', '>'], '', str_replace($senderEmail, '', $from)));

            // Extract body (plain text)
            $body = '';
            $parts = $payload->getParts();
            if ($parts) {
                foreach ($parts as $part) {
                    if ($part->getMimeType() === 'text/plain') {
                        $body = $this->decodeBody($part->getBody()->getData());
                        break;
                    }
                }
            }

            // Fallback to body if no parts
            if (!$body && $payload->getBody()->getData()) {
                $body = $this->decodeBody($payload->getBody()->getData());
            }

            // Strip quoted replies
            $body = preg_replace('/On .+ wrote:.*$/s', '', $body);
            $body = preg_replace('/-+Original Message-+.*/s', '', $body);
            $body = trim($body);

            if (empty($body)) {
                Log::info('Gmail message has no body, skipping', ['message_id' => $messageId]);
                return;
            }

            // Create conversation (keyed on threadId)
            $conversation = \App\Models\Conversation::firstOrCreate(
                ['channel_id' => $channel->id, 'sender_id' => $threadId],
                [
                    'business_id' => $channel->business_id,
                    'sender_name' => $senderName,
                    'sender_email' => $senderEmail,
                    'subject' => $subject,
                    'status' => 'open',
                    'last_message_at' => now(),
                ]
            );

            $conversation->update(['last_message_at' => now()]);

            // Resolve/link the business-scoped Customer (email is the strong key here)
            try {
                app(\App\Services\CustomerService::class)->attachToConversation($conversation);
            } catch (\Throwable $e) {
            Log::warning('Customer resolution failed for Gmail message', [
                'conversation_id' => $conversation->id,
                'exception'       => get_class($e),
                ]);
            }

            // Check if message already exists (deduplication)
            $existingMessage = \App\Models\Message::where('gmail_message_id', $messageId)->first();
            if ($existingMessage) {
                Log::info('Gmail message already processed');
                return;
            }

            // Create message
            $messageModel = \App\Models\Message::create([
                'conversation_id' => $conversation->id,
                'content' => $body,
                'direction' => 'inbound',
                'is_ai' => false,
                'status' => 'received',
                'gmail_message_id' => $messageId,
                'metadata' => array_filter([
                    'gmail_thread_id' => $threadId,
                    'gmail_in_reply_to' => $gmailInReplyTo,
                    'referenced_platform_message_id' => $gmailInReplyTo,
                ], fn ($value) => is_string($value) && $value !== ''),
            ]);

            Log::info('Gmail message saved', [
                'conversation_id' => $conversation->id,
                'message_id' => $messageModel->id,
                'gmail_message_id' => $messageId,
            ]);

            if ($channel->user_id) {
                broadcast(new \App\Events\MessageReceived($messageModel, $conversation, $channel->user_id));
            }

            // Implement message debounce for Gmail as well
            $debounceKey = "debounce:conversation:{$conversation->id}";
            $debounceWindow = 10; // 10 seconds debounce window
            
            if (Cache::has($debounceKey)) {
                Log::info('Gmail message debounced - AI reply skipped', [
                    'conversation_id' => $conversation->id,
                    'message_id' => $messageModel->id
                ]);
            } else {
                \App\Jobs\ProcessAutoReply::dispatch($messageModel->id);
                Cache::put($debounceKey, true, $debounceWindow);
            }

        } catch (\Exception $e) {
            Log::error('Error processing Gmail message', [
                'exception' => get_class($e),
            ]);
        }
    }

    private function decodeBody(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/'));
    }
}
