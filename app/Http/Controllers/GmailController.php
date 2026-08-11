<?php

namespace App\Http\Controllers;

use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Google\Client as GoogleClient;
use Google\Service\Gmail;

class GmailController extends Controller
{
    private function makeClient(): GoogleClient
    {
        $client = new GoogleClient();
        $client->setClientId(env('GOOGLE_CLIENT_ID'));
        $client->setClientSecret(env('GOOGLE_CLIENT_SECRET'));
        $client->setRedirectUri(env('GOOGLE_REDIRECT_URI'));
        $client->addScope(Gmail::GMAIL_MODIFY); // superset of READONLY; also allows marking messages read
        $client->addScope(Gmail::GMAIL_SEND);
        $client->setAccessType('offline');
        $client->setPrompt('consent');
        return $client;
    }

    public function connect(Request $request)
    {
        $client = $this->makeClient();
        $client->setState($request->user()->id);
        $url = $client->createAuthUrl();
        return response()->json(['url' => $url]);
    }

    public function callback(Request $request)
    {
        $code   = $request->get('code');
        $userId = $request->get('state');

        if (!$code) {
            return redirect(env('FRONTEND_URL') . '/dashboard/channels?error=gmail_denied');
        }

        $client = $this->makeClient();

        try {
            $token = $client->fetchAccessTokenWithAuthCode($code);

            if (isset($token['error'])) {
                Log::error('Gmail token error', $token);
                return redirect(env('FRONTEND_URL') . '/dashboard/channels?error=gmail_token');
            }

            $client->setAccessToken($token);

            // Get Gmail profile to find the email address
            $gmail   = new Gmail($client);
            $profile = $gmail->users->getProfile('me');
            $email   = $profile->getEmailAddress();

            $businessProfile = \App\Models\BusinessProfile::where('user_id', $userId)->first();

            $channel = Channel::updateOrCreate(
                ['user_id' => $userId, 'type' => 'gmail'],
                [
                    'page_name'     => $email,
                    'access_token'  => json_encode($token),   // mutator encrypts this automatically
                    'refresh_token' => isset($token['refresh_token']) ? encrypt($token['refresh_token']) : null,
                    'status'        => 'connected',
                    'connected_at'  => now(),
                    'business_id'   => $businessProfile ? $businessProfile->id : null,
                ]
            );

            // Set up Gmail Push Notifications via watch()
            $this->setupGmailWatch($channel);

            // Sync historical messages in the background
            \App\Jobs\SyncGmailHistory::dispatch($channel->id);

            Log::info('Gmail channel connected', ['user_id' => $userId, 'email' => $email]);
            return redirect(env('FRONTEND_URL') . '/dashboard/channels?success=gmail');

        } catch (\Exception $e) {
            Log::error('Gmail callback exception', ['error' => $e->getMessage()]);
            return redirect(env('FRONTEND_URL') . '/dashboard/channels?error=gmail_exception');
        }
    }

    public function getAuthenticatedClient(Channel $channel): ?GoogleClient
    {
        try {
            $tokenData = json_decode(decrypt($channel->getRawOriginal('access_token')), true);
            $client    = $this->makeClient();
            $client->setAccessToken($tokenData);

            if ($client->isAccessTokenExpired()) {
                $refreshToken = $channel->refresh_token ?? ($tokenData['refresh_token'] ?? null);

                if (!$refreshToken) {
                    Log::error('Gmail token expired and no refresh token — marking disconnected', [
                        'channel_id' => $channel->id,
                    ]);
                    $channel->update([
                        'status'   => 'disconnected',
                        'metadata' => array_merge($channel->metadata ?? [], [
                            'disconnect_reason' => 'no_refresh_token',
                            'disconnected_at'   => now()->toISOString(),
                        ]),
                    ]);
                    return null;
                }

                // If refresh_token is stored separately (encrypted), decrypt it
                try {
                    $refreshToken = decrypt($refreshToken);
                } catch (\Exception $e) {
                    // Already plain string from tokenData
                }

                $newToken = $client->fetchAccessTokenWithRefreshToken($refreshToken);

                // Detect invalid_grant (revoked / expired refresh token)
                if (isset($newToken['error'])) {
                    $errorCode = $newToken['error'];
                    Log::error('Gmail refresh token error — marking channel disconnected', [
                        'channel_id' => $channel->id,
                        'error'      => $errorCode,
                    ]);
                    $channel->update([
                        'status'   => 'disconnected',
                        'metadata' => array_merge($channel->metadata ?? [], [
                            'disconnect_reason' => $errorCode,   // e.g. "invalid_grant"
                            'disconnected_at'   => now()->toISOString(),
                        ]),
                    ]);
                    return null;  // Stop retrying — caller must prompt user to reconnect
                }

                // Persist refreshed token
                Channel::where('id', $channel->id)->update([
                    'access_token' => encrypt(json_encode($client->getAccessToken())),
                    'updated_at'   => now(),
                ]);
            }

            return $client;
        } catch (\Exception $e) {
            Log::error('Gmail client error', ['error' => $e->getMessage(), 'channel_id' => $channel->id]);
            return null;
        }
    }

    private function setupGmailWatch(Channel $channel): void
    {
        $client = $this->getAuthenticatedClient($channel);
        if (!$client) {
            Log::error('Gmail watch: could not get authenticated client', ['channel_id' => $channel->id]);
            return;
        }

        try {
            $gmail = new Gmail($client);
            $topicName = env('GMAIL_PUBSUB_TOPIC');

            if (!$topicName) {
                Log::warning('Gmail watch: GMAIL_PUBSUB_TOPIC not set in .env');
                return;
            }

            $watchRequest = new \Google\Service\Gmail\WatchRequest();
            $watchRequest->setTopicName($topicName);
            $watchRequest->setLabelIds(['INBOX']);

            $watchResponse = $gmail->users->watch('me', $watchRequest);

            $historyId = $watchResponse->getHistoryId();
            $expiration = $watchResponse->getExpiration(); // Unix timestamp in milliseconds

            $channel->update([
                'gmail_history_id' => $historyId,
                'gmail_watch_expires_at' => \Carbon\Carbon::createFromTimestampMs($expiration),
            ]);

            Log::info('Gmail watch set up', [
                'channel_id' => $channel->id,
                'history_id' => $historyId,
                'expires_at' => $channel->gmail_watch_expires_at,
            ]);

        } catch (\Exception $e) {
            Log::error('Gmail watch setup failed', [
                'error' => $e->getMessage(),
                'channel_id' => $channel->id,
            ]);
        }
    }

    /**
     * Poll Gmail inbox for new messages and save them.
     * Called by: GET /api/channels/gmail/fetch (authenticated)
     */
    public function fetchEmails(Request $request)
    {
        $channel = Channel::where('user_id', auth()->id())
            ->where('type', 'gmail')
            ->where('status', 'connected')
            ->first();

        if (!$channel) {
            return response()->json(['message' => 'No Gmail channel connected'], 404);
        }

        $client = $this->getAuthenticatedClient($channel);
        if (!$client) {
            return response()->json(['message' => 'Gmail auth failed'], 401);
        }

        $gmail    = new Gmail($client);
        $newCount = 0;

        try {
            // Only fetch messages newer than last fetch to avoid duplicates
            $after = $channel->updated_at
                ? 'after:' . $channel->updated_at->subMinutes(2)->timestamp
                : 'after:' . now()->subDays(7)->timestamp;

            $results = $gmail->users_messages->listUsersMessages('me', [
                'labelIds'   => ['INBOX'],
                'q'          => "is:unread {$after} -from:me",
                'maxResults' => 20,
            ]);

            $msgs = $results->getMessages() ?? [];

            foreach ($msgs as $msgRef) {
                $msgId = $msgRef->getId();

                // Skip if already saved
                if (Message::where('gmail_message_id', $msgId)->exists()) {
                    continue;
                }

                // Fetch full message
                $full    = $gmail->users_messages->get('me', $msgId, ['format' => 'full']);
                $headers = collect($full->getPayload()->getHeaders())->keyBy('name');

                $from        = $headers->get('From')?->getValue()       ?? 'Unknown';
                $subject     = $headers->get('Subject')?->getValue()     ?? '(no subject)';
                $gmailMsgId  = $headers->get('Message-ID')?->getValue()  ?? $msgId;
                $threadId    = $full->getThreadId();

                // Real sent timestamp from Gmail (milliseconds)
                $sentAt = \Carbon\Carbon::createFromTimestampMs($full->getInternalDate());

                // Extract sender email and name
                preg_match('/<(.+?)>/', $from, $m);
                $senderEmail = $m[1] ?? $from;
                $senderName  = trim(preg_replace('/<.+?>/', '', $from)) ?: $senderEmail;

                // Extract plain text body (used for AI context/previews)
                // and HTML body (used to render the styled email in the inbox UI)
                $body = $this->extractBody($full->getPayload());
                $bodyHtml = $this->extractHtmlBody($full->getPayload());
                
                // Log for debugging
                Log::info('Gmail message extraction', [
                    'message_id' => $msgId,
                    'has_plain_text' => !empty($body),
                    'has_html' => !empty($bodyHtml),
                    'html_length' => strlen($bodyHtml),
                    'plain_text_length' => strlen($body),
                ]);
                
                if (!$body && !$bodyHtml) continue;

                // Find or create conversation keyed on threadId
                $conversation = Conversation::firstOrCreate(
                    ['channel_id' => $channel->id, 'sender_id' => $threadId],
                    [
                        'business_id'     => $channel->business_id,
                        'sender_name'     => $senderName,
                        'sender_email'    => $senderEmail,
                        'subject'         => $subject,
                        'status'          => 'open',
                        'last_message_at' => $sentAt,
                    ]
                );

                $conversation->update(['last_message_at' => $sentAt]);

                $message = Message::create([
                    'conversation_id'  => $conversation->id,
                    // Plain text always populated (falls back to a stripped
                    // version of the HTML) — AI context and previews rely on this.
                    'content'          => $body ?: $this->htmlToPlainText($bodyHtml),
                    'content_html'     => $bodyHtml ?: null,
                    'direction'        => 'inbound',
                    'is_ai'            => false,
                    'status'           => 'received',
                    'gmail_message_id' => $gmailMsgId,
                    'created_at'       => $sentAt,
                    'updated_at'       => $sentAt,
                ]);

                // Implement message debounce for Gmail
                $debounceKey = "debounce:conversation:{$conversation->id}";
                $debounceWindow = 10; // 10 seconds debounce window
                
                if (Cache::has($debounceKey)) {
                    Log::info('Gmail message debounced - AI reply skipped', [
                        'conversation_id' => $conversation->id,
                        'message_id' => $message->id
                    ]);
                } else {
                    \App\Jobs\ProcessAutoReply::dispatch($message->id);
                    Cache::put($debounceKey, true, $debounceWindow);
                }
                $newCount++;
            }

            return response()->json(['fetched' => $newCount]);

        } catch (\Exception $e) {
            Log::error('Gmail fetch error', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Fetch failed: ' . $e->getMessage()], 500);
        }
    }

    public function extractBody($payload): string
    {
        $found = $this->findPartByMimeType($payload, 'text/plain');
        if ($found !== null) {
            return $found;
        }

        // Fallback: body directly (single-part messages with no explicit
        // text/plain part, e.g. a bare text/html or text/plain payload).
        $data = $payload->getBody()->getData();
        return $data ? $this->decodePartData($data) : '';
    }

    /**
     * Extract the HTML body of an email, if one exists. Used for rendering
     * Gmail messages as styled HTML in the inbox UI. Returns '' when the
     * email has no text/html part (plain-text-only emails).
     */
    public function extractHtmlBody($payload): string
    {
        // First try to find text/html part recursively
        $found = $this->findPartByMimeType($payload, 'text/html');
        if ($found !== null && !empty($found)) {
            return $found;
        }

        // Some single-part messages are sent with Content-Type: text/html
        // directly on the top-level payload with no nested parts at all.
        if ($payload->getMimeType() === 'text/html') {
            $data = $payload->getBody()->getData();
            if ($data) {
                $decoded = $this->decodePartData($data);
                if (!empty($decoded)) {
                    return $decoded;
                }
            }
        }

        // Try multipart/alternative which usually contains both plain and HTML
        if (strpos($payload->getMimeType(), 'multipart') === 0) {
            foreach ($payload->getParts() ?? [] as $part) {
                if ($part->getMimeType() === 'text/html') {
                    $data = $part->getBody()->getData();
                    if ($data) {
                        $decoded = $this->decodePartData($data);
                        if (!empty($decoded)) {
                            return $decoded;
                        }
                    }
                }
            }
        }

        return '';
    }

    /**
     * Recursively search a Gmail message payload for the first part matching
     * the given MIME type (e.g. 'text/plain' or 'text/html'). Gmail payloads
     * can nest arbitrarily deep (multipart/mixed > multipart/related >
     * multipart/alternative), so a single level of getParts() isn't enough.
     */
    private function findPartByMimeType($payload, string $mimeType): ?string
    {
        if ($payload->getMimeType() === $mimeType) {
            $data = $payload->getBody()->getData();
            if ($data) {
                return $this->decodePartData($data);
            }
        }

        foreach ($payload->getParts() ?? [] as $part) {
            $found = $this->findPartByMimeType($part, $mimeType);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * Convert HTML email body to clean plain text for AI context / previews.
     * Strips <style> and <script> blocks first so that CSS/JS source text
     * does not bleed into the stored plain-text content.
     */
    private function htmlToPlainText(string $html): string
    {
        // Remove style and script blocks entirely (including their content)
        $text = preg_replace('/<(style|script)[^>]*>.*?<\/\1>/si', '', $html);
        // Decode HTML entities and strip remaining tags
        $text = html_entity_decode(strip_tags($text ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Collapse whitespace
        return trim(preg_replace('/\s+/', ' ', $text));
    }

    private function decodePartData(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/'));
    }
}