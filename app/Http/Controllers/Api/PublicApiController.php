<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PublicApiController extends Controller
{
    /**
     * Send a message (public API)
     */
    public function sendMessage(Request $request)
    {
        $request->validate([
            'api_key' => 'required|string',
            'conversation_id' => 'required|integer',
            'message' => 'required|string',
        ]);

        // Validate API key
        $apiKey = ApiKey::where('key', $request->api_key)
            ->active()
            ->notExpired()
            ->first();

        if (!$apiKey) {
            return response()->json(['error' => 'Invalid or expired API key'], 401);
        }

        // Update last used
        $apiKey->update(['last_used_at' => now()]);

        // Check permissions
        if (!in_array('send_message', $apiKey->permissions ?? ['*'])) {
            return response()->json(['error' => 'Permission denied'], 403);
        }

        // Verify conversation belongs to user
        $conversation = Conversation::whereHas('channel', function ($q) use ($apiKey) {
                $q->where('user_id', $apiKey->user_id);
            })
            ->find($request->conversation_id);

        if (!$conversation) {
            return response()->json(['error' => 'Conversation not found'], 404);
        }

        // Create message
        $message = Message::create([
            'conversation_id' => $conversation->id,
            'content' => $request->message,
            'direction' => 'outbound',
            'status' => 'api',
            'is_ai' => false,
            'source' => 'public_api',
            'send_status' => 'pending',
        ]);

        return response()->json([
            'success' => true,
            'message_id' => $message->id,
            'status' => 'queued',
        ]);
    }

    /**
     * Get conversations (public API)
     */
    public function getConversations(Request $request)
    {
        $request->validate([
            'api_key' => 'required|string',
        ]);

        $apiKey = ApiKey::where('key', $request->api_key)
            ->active()
            ->notExpired()
            ->first();

        if (!$apiKey) {
            return response()->json(['error' => 'Invalid or expired API key'], 401);
        }

        $apiKey->update(['last_used_at' => now()]);

        if (!in_array('read_conversations', $apiKey->permissions ?? ['*'])) {
            return response()->json(['error' => 'Permission denied'], 403);
        }

        $conversations = Conversation::whereHas('channel', function ($q) use ($apiKey) {
                $q->where('user_id', $apiKey->user_id);
            })
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
            'api_key' => 'required|string',
        ]);

        $apiKey = ApiKey::where('key', $request->api_key)
            ->active()
            ->notExpired()
            ->first();

        if (!$apiKey) {
            return response()->json(['error' => 'Invalid or expired API key'], 401);
        }

        $apiKey->update(['last_used_at' => now()]);

        if (!in_array('read_messages', $apiKey->permissions ?? ['*'])) {
            return response()->json(['error' => 'Permission denied'], 403);
        }

        $conversation = Conversation::whereHas('channel', function ($q) use ($apiKey) {
                $q->where('user_id', $apiKey->user_id);
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
}
