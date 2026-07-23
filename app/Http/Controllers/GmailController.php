<?php

namespace App\Http\Controllers;

use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
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
        $client->addScope(Gmail::GMAIL_READONLY);
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
                    Log::error('Gmail token expired and no refresh token', ['channel_id' => $channel->id]);
                    return null;
                }
                // If refresh_token is stored separately (encrypted), decrypt it
                try {
                    $refreshToken = decrypt($refreshToken);
                } catch (\Exception $e) {
                    // Already plain string from tokenData
                }
                $client->fetchAccessTokenWithRefreshToken($refreshToken);
                $newToken = $client->getAccessToken();

                // Persist updated token
                Channel::where('id', $channel->id)->update([
                    'access_token' => encrypt(json_encode($newToken)),
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

                // Extract plain text body
                $body = $this->extractBody($full->getPayload());
                if (!$body) continue;

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
                    'content'          => $body,
                    'direction'        => 'inbound',
                    'is_ai'            => false,
                    'status'           => 'received',
                    'gmail_message_id' => $gmailMsgId,
                    'created_at'       => $sentAt,
                    'updated_at'       => $sentAt,
                ]);

                \App\Jobs\ProcessAutoReply::dispatch($message->id);
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
        // Try parts first (multipart)
        $parts = $payload->getParts() ?? [];
        foreach ($parts as $part) {
            if ($part->getMimeType() === 'text/plain') {
                $data = $part->getBody()->getData();
                if ($data) return quoted_printable_decode(base64_decode(strtr($data, '-_', '+/')));
            }
            // Nested parts
            $nested = $part->getParts() ?? [];
            foreach ($nested as $sub) {
                if ($sub->getMimeType() === 'text/plain') {
                    $data = $sub->getBody()->getData();
                    if ($data) return quoted_printable_decode(base64_decode(strtr($data, '-_', '+/')));
                }
            }
        }
        // Fallback: body directly
        $data = $payload->getBody()->getData();
        return $data ? quoted_printable_decode(base64_decode(strtr($data, '-_', '+/'))) : '';
    }
}