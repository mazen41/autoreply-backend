<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\ChannelMessageService;
use App\Services\ConsentGate;
use App\Services\BusinessAccessService;
use Illuminate\Http\Request;

class PublicApiController extends Controller
{
    /**
     * Send a message (public API)
     */
    public function sendMessage(Request $request)
    {
        $request->validate([
            'conversation_id' => 'required|integer',
            'message' => 'required|string',
        ]);

        $apiKey = $this->authenticateApiKey($request);

        if (!$apiKey) {
            return response()->json(['error' => 'Invalid or expired API key'], 401);
        }

        // Update last used
        $apiKey->update(['last_used_at' => now()]);

        // Check permissions
        if (!$this->hasPermission($apiKey, 'send_message', 'messages:write')) {
            return response()->json(['error' => 'Permission denied'], 403);
        }

        if (!$apiKey->business_id) {
            return response()->json(['error' => 'API key is not scoped to a business.'], 403);
        }
        app(BusinessAccessService::class)->authorize($apiKey->user, $apiKey->business_id, 'write');

        // Verify conversation belongs to user
        $conversation = Conversation::with('channel')
            ->where('business_id', $apiKey->business_id)
            ->whereHas('channel', function ($q) use ($apiKey) {
                $q->where('channels.business_id', $apiKey->business_id)
                    ->whereColumn('channels.business_id', 'conversations.business_id');
            })
            ->find($request->conversation_id);

        if (!$conversation) {
            return response()->json(['error' => 'Conversation not found'], 404);
        }

        $channel = $conversation->channel;
        if (!$channel || $channel->status !== 'connected') {
            return response()->json([
                'error' => 'The conversation channel is not connected.',
                'error_code' => 'CHANNEL_UNAVAILABLE',
            ], 422);
        }

        $consent = app(ConsentGate::class)->checkConversation($conversation, 'service');
        if (!$consent->allowed) {
            return response()->json([
                'error' => 'The contact is not eligible for a service message.',
                'error_code' => 'CONSENT_REQUIRED',
            ], 403);
        }

        $requiresMessagingWindow = in_array($channel->type, ['facebook', 'instagram'], true)
            || ($channel->type === 'whatsapp' && ($channel->metadata['whatsapp_provider'] ?? null) === 'cloud');
        if ($requiresMessagingWindow) {
            $lastInbound = $conversation->messages()
                ->where('direction', 'inbound')
                ->latest('created_at')
                ->first();
            if (!$lastInbound || !$lastInbound->created_at || $lastInbound->created_at->lte(now()->subHours(24))) {
                return response()->json([
                    'error' => 'The customer messaging window has expired.',
                    'error_code' => 'OUTSIDE_WINDOW',
                ], 403);
            }
        }

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'content' => $request->message,
            'direction' => 'outbound',
            'status' => 'queued',
            'is_ai' => false,
            'source' => 'public_api',
            'send_status' => 'pending',
        ]);

        $sent = app(ChannelMessageService::class)->sendMessage($channel, $conversation, $message);
        $message->refresh();

        return response()->json([
            'success' => $sent,
            'message_id' => $message->id,
            'status' => $message->send_status,
            'provider_message_id' => data_get($message->metadata, 'platform_message_id'),
        ], $sent ? 200 : 502);
    }

    /**
     * Get conversations (public API)
     */
    public function getConversations(Request $request)
    {
        $request->validate([
        ]);

        $apiKey = $this->authenticateApiKey($request);

        if (!$apiKey) {
            return response()->json(['error' => 'Invalid or expired API key'], 401);
        }

        $apiKey->update(['last_used_at' => now()]);

        if (!$this->hasPermission($apiKey, 'read_conversations', 'conversations:read')) {
            return response()->json(['error' => 'Permission denied'], 403);
        }

        if ($apiKey->business_id) {
            app(BusinessAccessService::class)->authorize($apiKey->user, $apiKey->business_id, 'read');
        }

        $conversations = Conversation::query();
        if ($apiKey->business_id) {
            $conversations->where('business_id', $apiKey->business_id)->whereHas('channel', function ($q) use ($apiKey) {
                $q->where('business_id', $apiKey->business_id);
            });
        } else {
            $conversations->whereRaw('1 = 0');
        }
        $conversations = $conversations
            ->with(['channel', 'latestMessage'])
            ->orderBy('last_message_at', 'desc')
            ->paginate(50);

        return response()->json($conversations);
    }

    /**
     * Get conversation messages (public API)
     */
    public function getMessages(Request $request, $conversationId)
    {
        $request->validate([
        ]);

        $apiKey = $this->authenticateApiKey($request);

        if (!$apiKey) {
            return response()->json(['error' => 'Invalid or expired API key'], 401);
        }

        $apiKey->update(['last_used_at' => now()]);

        if (!$this->hasPermission($apiKey, 'read_messages', 'messages:read')) {
            return response()->json(['error' => 'Permission denied'], 403);
        }

        if (!$apiKey->business_id) {
            return response()->json(['error' => 'API key is not scoped to a business.'], 403);
        }
        app(BusinessAccessService::class)->authorize($apiKey->user, $apiKey->business_id, 'read');

        $conversation = Conversation::where('business_id', $apiKey->business_id)
            ->whereHas('channel', function ($q) use ($apiKey) {
                $q->where('business_id', $apiKey->business_id);
            })
            ->find($conversationId);

        if (!$conversation) {
            return response()->json(['error' => 'Conversation not found'], 404);
        }

        $messages = $conversation->messages()
            ->orderBy('created_at', 'asc')
            ->paginate(100);

        return response()->json($messages);
    }

    private function hasPermission(ApiKey $apiKey, string ...$acceptedPermissions): bool
    {
        $permissions = $apiKey->permissions ?? ['*'];
        return in_array('*', $permissions, true)
            || count(array_intersect($acceptedPermissions, $permissions)) > 0;
    }

    private function authenticateApiKey(Request $request): ?ApiKey
    {
        // Credentials in query strings can leak through access logs, browser
        // history, and referrer headers. Accept only request headers.
        $secret = $request->bearerToken() ?: $request->header('X-API-Key');
        if (!is_string($secret) || $secret === '') {
            return null;
        }

        return ApiKey::where('secret_hash', hash('sha256', $secret))
            ->active()
            ->notExpired()
            ->first();
    }
}
