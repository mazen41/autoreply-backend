<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WebChatSession;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class WebChatController extends Controller
{
    private $notificationService;

    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    /**
     * Create or get web chat session
     */
    public function createSession(Request $request)
    {
        $request->validate([
            'business_id' => 'required|integer|exists:business_profiles,id',
            'session_id' => 'required|string',
            'page_url' => 'nullable|string',
            'visitor_name' => 'nullable|string',
            'visitor_email' => 'nullable|email',
        ]);

        $session = WebChatSession::updateOrCreate(
            [
                'session_id' => $request->session_id,
            ],
            [
                'business_id' => $request->business_id,
                'user_id' => Auth::id(),
                'visitor_name' => $request->visitor_name,
                'visitor_email' => $request->visitor_email,
                'page_url' => $request->page_url,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'is_online' => true,
                'last_activity_at' => now(),
            ]
        );

        // Create conversation if doesn't exist
        $conversation = Conversation::where('web_chat_session_id', $session->id)->first();

        if (!$conversation) {
            $conversation = Conversation::create([
                'business_id' => $request->business_id,
                'channel_id' => null, // Web chat doesn't use traditional channels
                'sender_id' => $request->session_id,
                'sender_name' => $request->visitor_name ?? 'Website Visitor',
                'status' => 'active',
                'source' => 'web_chat',
                'web_chat_session_id' => $session->id,
                'last_message_at' => now(),
            ]);
        }

        // Broadcast new session event
        broadcast(new \App\Events\WebChatSessionCreated($session));

        return response()->json([
            'success' => true,
            'session' => $session,
            'conversation_id' => $conversation->id,
        ]);
    }

    /**
     * Send message from web chat
     */
    public function sendMessage(Request $request)
    {
        $request->validate([
            'session_id' => 'required|string',
            'message' => 'required|string',
        ]);

        $session = WebChatSession::where('session_id', $request->session_id)->first();

        if (!$session) {
            return response()->json(['error' => 'Session not found'], 404);
        }

        $conversation = Conversation::where('web_chat_session_id', $session->id)->first();

        if (!$conversation) {
            return response()->json(['error' => 'Conversation not found'], 404);
        }

        // Create message
        $message = Message::create([
            'conversation_id' => $conversation->id,
            'content' => $request->message,
            'direction' => 'inbound',
            'status' => 'received',
            'is_ai' => false,
            'source' => 'web_chat',
            'send_status' => 'sent',
        ]);

        // Update conversation
        $conversation->update([
            'last_message_at' => now(),
            'status' => 'active',
        ]);

        // Update session activity
        $session->update([
            'last_activity_at' => now(),
            'is_online' => true,
        ]);

        // Notify business owner
        $this->notificationService->newMessage(
            $session->business->user_id,
            $session->visitor_name ?? 'Website Visitor',
            $conversation->id
        );

        // Broadcast message event
        broadcast(new \App\Events\WebChatMessageReceived($message));

        // Trigger AI response if enabled
        if ($session->business->ai_enabled) {
            \App\Jobs\ProcessAutoReply::dispatch($message->id);
        }

        return response()->json([
            'success' => true,
            'message' => $message,
        ]);
    }

    /**
     * Get messages for a session
     */
    public function getMessages(Request $request, $sessionId)
    {
        $session = WebChatSession::where('session_id', $sessionId)->first();

        if (!$session) {
            return response()->json(['error' => 'Session not found'], 404);
        }

        $conversation = Conversation::where('web_chat_session_id', $session->id)->first();

        if (!$conversation) {
            return response()->json(['messages' => []]);
        }

        $messages = $conversation->messages()
            ->orderBy('created_at', 'asc')
            ->get();

        return response()->json(['messages' => $messages]);
    }

    /**
     * Update session online status
     */
    public function updateOnlineStatus(Request $request, $sessionId)
    {
        $request->validate([
            'is_online' => 'required|boolean',
        ]);

        $session = WebChatSession::where('session_id', $sessionId)->first();

        if (!$session) {
            return response()->json(['error' => 'Session not found'], 404);
        }

        $session->update([
            'is_online' => $request->is_online,
            'last_activity_at' => now(),
        ]);

        // Broadcast status change
        broadcast(new \App\Events\WebChatStatusChanged($session));

        return response()->json(['success' => true]);
    }

    /**
     * Get active web chat sessions for a business
     */
    public function getActiveSessions(Request $request, $businessId)
    {
        $sessions = WebChatSession::where('business_id', $businessId)
            ->with(['conversation'])
            ->where('is_online', true)
            ->orderBy('last_activity_at', 'desc')
            ->get();

        return response()->json(['sessions' => $sessions]);
    }
}
