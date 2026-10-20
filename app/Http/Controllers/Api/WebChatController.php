<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WebChatSession;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Channel;
use App\Models\BusinessProfile;
use App\Services\ActiveBusiness;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class WebChatController extends Controller
{
    private $notificationService;

    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    public function configuration(Request $request, ActiveBusiness $activeBusiness)
    {
        $business = $activeBusiness->resolve($request, permission: 'read');
        return response()->json([
            'enabled' => (bool) $business->web_chat_enabled,
            'allowed_domains' => $business->web_chat_allowed_domains ?? [],
        ]);
    }

    public function updateConfiguration(Request $request, ActiveBusiness $activeBusiness)
    {
        $business = $activeBusiness->resolve($request, permission: 'write');
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'allowed_domains' => ['required_if:enabled,true', 'array', 'max:50'],
            'allowed_domains.*' => ['required', 'string', 'max:255'],
        ]);
        $domains = collect($data['allowed_domains'] ?? [])->map(function (string $domain): string {
            $domain = strtolower(trim($domain));
            $host = parse_url(str_contains($domain, '://') ? $domain : 'https://' . $domain, PHP_URL_HOST);
            abort_unless(is_string($host) && preg_match('/^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $host), 422, 'Enter a valid website domain.');
            return $host;
        })->unique()->values()->all();
        $business->forceFill(['web_chat_enabled' => $data['enabled'], 'web_chat_allowed_domains' => $domains])->save();
        return $this->configuration($request, $activeBusiness);
    }

    public function attribution(Request $request, ActiveBusiness $activeBusiness)
    {
        $business = $activeBusiness->resolve($request, permission: 'conversation_read');
        $data = $request->validate(['days' => ['sometimes', 'integer', 'min:1', 'max:365']]);
        $since = now()->subDays((int) ($data['days'] ?? 30));
        $rows = WebChatSession::query()->where('business_id', $business->id)->where('created_at', '>=', $since)
            ->selectRaw("COALESCE(utm_source, 'direct') as source, COALESCE(utm_medium, 'none') as medium, COALESCE(utm_campaign, 'none') as campaign, COUNT(*) as sessions")
            ->groupBy('utm_source', 'utm_medium', 'utm_campaign')->orderByDesc('sessions')->limit(100)->get();
        $total = WebChatSession::query()->where('business_id', $business->id)->where('created_at', '>=', $since)->count();
        return response()->json(['days' => (int) ($data['days'] ?? 30), 'total_sessions' => $total, 'sources' => $rows]);
    }

    /**
     * Create or get web chat session
     */
    public function createSession(Request $request)
    {
        $data = $request->validate([
            'business_id' => 'required|integer|exists:business_profiles,id',
            'session_id' => 'nullable|string|max:100',
            'page_url' => 'nullable|url|max:2048',
            'referrer_url' => 'nullable|url|max:2048',
            'site_url' => 'nullable|url|max:2048',
            'visitor_name' => 'nullable|string|max:191',
            'visitor_email' => 'nullable|email|max:191',
            'utm_source' => 'nullable|string|max:191',
            'utm_medium' => 'nullable|string|max:191',
            'utm_campaign' => 'nullable|string|max:191',
        ]);

        $business = BusinessProfile::findOrFail($data['business_id']);
        abort_unless($business->web_chat_enabled, 404, 'Website chat is not enabled for this business.');
        // WebChatCors validates the network Origin. When the widget is inside
        // our hosted iframe, the browser Origin is our own app host, so use the
        // iframe's parent referrer to check the merchant's allowed-domain list.
        $origin = (string) $request->header('origin');
        $originHost = strtolower((string) parse_url($origin, PHP_URL_HOST));
        $appHosts = collect([config('app.url'), config('app.frontend_url')])
            ->map(fn ($url) => strtolower((string) parse_url((string) $url, PHP_URL_HOST)));
        $claimedSiteUrl = $data['site_url'] ?? $data['referrer_url'] ?? $data['page_url'] ?? '';
        $claimedSiteHost = strtolower((string) parse_url((string) $claimedSiteUrl, PHP_URL_HOST));
        $siteHost = $appHosts->contains($originHost) && $claimedSiteHost !== '' ? $claimedSiteHost : $originHost;
        $allowedDomains = collect($business->web_chat_allowed_domains ?? [])->map(fn ($domain) => strtolower((string) $domain));
        $isAllowedDomain = $allowedDomains->contains(fn ($domain) => $siteHost === $domain || str_ends_with($siteHost, '.' . $domain));
        abort_unless($siteHost !== '' && ($isAllowedDomain || $appHosts->contains($siteHost)), 403, 'This website is not authorized to use the chat widget.');

        $sessionId = trim((string) ($data['session_id'] ?? ''));
        $existingSession = $sessionId !== '' ? WebChatSession::query()->where('business_id', $business->id)->where('session_id', $sessionId)->first() : null;
        if ($sessionId !== '' && !$existingSession) abort(404, 'Chat session not found.');
        $sessionId = $existingSession?->session_id ?? Str::random(64);

        $session = WebChatSession::updateOrCreate(
            ['session_id' => $sessionId],
            [
                'business_id' => $business->id,
                'user_id' => null,
                'visitor_name' => $data['visitor_name'] ?? $existingSession?->visitor_name,
                'visitor_email' => $data['visitor_email'] ?? $existingSession?->visitor_email,
                'page_url' => $data['page_url'] ?? $existingSession?->page_url,
                'referrer_url' => $data['referrer_url'] ?? $existingSession?->referrer_url,
                'utm_source' => $data['utm_source'] ?? $existingSession?->utm_source,
                'utm_medium' => $data['utm_medium'] ?? $existingSession?->utm_medium,
                'utm_campaign' => $data['utm_campaign'] ?? $existingSession?->utm_campaign,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'is_online' => true,
                'last_activity_at' => now(),
            ]
        );

        // Create conversation if doesn't exist
        $conversation = Conversation::where('web_chat_session_id', $session->id)->first();

        if (!$conversation) {
            $business = BusinessProfile::findOrFail($session->business_id);
            $channel = Channel::firstOrCreate(
                ['business_id' => $business->id, 'type' => 'web_chat', 'page_id' => 'web-chat-' . $business->id],
                [
                    'user_id' => $business->user_id,
                    'page_name' => 'Website Chat',
                    'access_token' => '',
                    'status' => 'connected',
                    'connected_at' => now(),
                    'ai_enabled' => false,
                ]
            );

            $conversation = Conversation::create([
                'business_id' => $business->id,
                'channel_id' => $channel->id,
                'sender_id' => $session->session_id,
                'sender_name' => $session->visitor_name ?? 'Website Visitor',
                'status' => 'active',
                'web_chat_session_id' => $session->id,
                'last_message_at' => now(),
            ]);
        }

        if (!$conversation->channel_id) {
            $business = BusinessProfile::findOrFail($session->business_id);
            $channel = Channel::firstOrCreate(
                ['business_id' => $business->id, 'type' => 'web_chat', 'page_id' => 'web-chat-' . $business->id],
                [
                    'user_id' => $business->user_id,
                    'page_name' => 'Website Chat',
                    'access_token' => '',
                    'status' => 'connected',
                    'connected_at' => now(),
                    'ai_enabled' => false,
                ]
            );
            $conversation->update(['channel_id' => $channel->id]);
        }

        // Resolve/link the business-scoped Customer (web-chat session identity)
        try {
            app(\App\Services\CustomerService::class)->attachToConversation($conversation);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Web chat: customer resolution failed', [
                'conversation_id' => $conversation->id,
                'exception_type' => get_class($e),
            ]);
        }

        // Broadcast new session event
        broadcast(new \App\Events\WebChatSessionCreated($session));

        return response()->json([
            'success' => true,
            'session' => $session,
            'session_id' => $session->session_id,
            'conversation_id' => $conversation->id,
        ], $existingSession ? 200 : 201);
    }

    /**
     * Send message from web chat
     */
    public function sendMessage(Request $request)
    {
        $request->validate([
            'session_id' => 'required|string|max:100',
            'message' => 'required|string|max:10000',
            'referenced_message_id' => 'nullable|string|max:255',
            'referenced_platform_message_id' => 'nullable|string|max:255',
        ]);

        $session = WebChatSession::where('session_id', $request->session_id)->first();

        if (!$session) {
            return response()->json(['error' => 'Session not found'], 404);
        }
        abort_unless($session->business?->web_chat_enabled, 410, 'Website chat is disabled for this business.');

        $conversation = Conversation::where('web_chat_session_id', $session->id)->first();

        if (!$conversation) {
            return response()->json(['error' => 'Conversation not found'], 404);
        }

        $reference = $request->input('referenced_platform_message_id')
            ?? $request->input('referenced_message_id');
        $validatedReference = null;
        if (is_string($reference) && $reference !== '') {
            $referencedMessage = ctype_digit($reference)
                ? Message::query()->where('conversation_id', $conversation->id)->whereKey((int) $reference)->first()
                : null;
            if ($referencedMessage) {
                $validatedReference = data_get($referencedMessage->metadata, 'platform_message_id')
                    ?? $referencedMessage->whatsapp_message_id
                    ?? $referencedMessage->gmail_message_id
                    ?? ('internal:' . $referencedMessage->id);
            } elseif (\App\Models\ProductMessageMap::query()
                ->where('conversation_id', $conversation->id)
                ->where('platform_message_id', $reference)
                ->exists()) {
                $validatedReference = $reference;
            }
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
            'metadata' => $validatedReference ? [
                'referenced_platform_message_id' => (string) $validatedReference,
                'quoted_message_id' => (string) $validatedReference,
            ] : null,
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
        abort_unless($session->business?->web_chat_enabled, 410, 'Website chat is disabled for this business.');

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
        abort_unless($session->business?->web_chat_enabled, 410, 'Website chat is disabled for this business.');

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
