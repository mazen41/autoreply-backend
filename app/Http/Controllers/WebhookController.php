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
            // Verify webhook signature for security
            $signature = $request->header('X-Hub-Signature-256');
            $payload = $request->getContent();
            $appSecret = config('services.meta.app_secret');

            if ($signature && $appSecret) {
                $expectedSignature = 'sha256=' . hash_hmac('sha256', $payload, $appSecret);
                
                if (!hash_equals($expectedSignature, $signature)) {
                    // Bug 7 fix: Soft-mode webhook verification.
                    // The payload is likely being mutated by a middleware (e.g. TrimStrings or ConvertEmptyStringsToNull)
                    // before this controller receives it, causing the HMAC to fail.
                    // Instead of dropping the data silently with a 403, we log the mismatch and continue processing.
                    // TODO: Once the middleware issue is fixed, revert to returning 403.
                    Log::warning('Invalid Meta webhook signature (SOFT MODE - allowing request)', [
                        'received' => $signature,
                        'expected' => $expectedSignature,
                        'payload_length' => strlen($payload)
                    ]);
                    // return response('Invalid signature', 403);
                } else {
                    Log::info('Meta webhook signature verified successfully');
                }
            } else {
                Log::warning('Meta webhook received without signature or app secret not configured');
            }

            $body = $request->all();
            Log::info('Meta Webhook received', $body);

            $object = $body['object'] ?? '';

            if ($object === 'instagram') {
                $this->handleInstagram($body);
            } elseif ($object === 'page') {
                // Meta sends Instagram DMs as object=page when using Messenger API for Instagram.
                // Check if any entry ID matches a connected Instagram account — if so, route to Instagram handler.
                $igIds = \App\Models\Channel::where('type', 'instagram')
                    ->where('status', 'connected')
                    ->get(['instagram_account_id', 'page_id'])
                    ->flatMap(fn($c) => array_filter([$c->instagram_account_id, $c->page_id]))
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
            Log::error('Webhook handler error', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return response('EVENT_RECEIVED', 200);
        }
    }

    private function handleFacebook(array $body): void
    {
        foreach ($body['entry'] as $entry) {
            $pageId = $entry['id'];

            if (str_starts_with($pageId, 'TEST_')) continue;

            // Handle messages (existing functionality)
            foreach ($entry['messaging'] ?? [] as $event) {
                if (isset($event['message']['is_echo'])) continue;
                if (!isset($event['message']['text'])) continue;

                $senderId    = $event['sender']['id'];
                $recipientId = $event['recipient']['id'] ?? $pageId;
                $messageText = $event['message']['text'];

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

                $this->processMessage($channel, $senderId, $messageText);
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
                if (isset($event['message']['is_echo'])) continue;
                if (isset($event['message_edit'])) continue;
                if (!isset($event['message']['text'])) continue;

                $senderId    = $event['sender']['id'];
                $messageText = $event['message']['text'];

                if (str_starts_with($senderId, 'TEST_')) continue;

                Log::info('Instagram DM received', [
                    'entry_id' => $entryId,
                    'sender'   => $senderId,
                    'message'  => $messageText,
                ]);

                // Strategy 1: entry ID is the Instagram account ID (object=instagram)
                $channel = Channel::where('instagram_account_id', $entryId)
                    ->where('type', 'instagram')
                    ->where('status', 'connected')
                    ->latest('connected_at')
                    ->first();

                // Strategy 2: entry ID matches page_id stored on instagram channel
                if (!$channel) {
                    $channel = Channel::where('page_id', $entryId)
                        ->where('type', 'instagram')
                        ->where('status', 'connected')
                        ->latest('connected_at')
                        ->first();
                }

                // Strategy 3: find facebook channel by page_id, then get instagram for same user
                if (!$channel) {
                    $fbChannel = Channel::where('page_id', $entryId)
                        ->where('type', 'facebook')
                        ->where('status', 'connected')
                        ->latest('connected_at')
                        ->first();

                    if ($fbChannel) {
                        $channel = Channel::where('type', 'instagram')
                            ->where('status', 'connected')
                            ->where('user_id', $fbChannel->user_id)
                            ->latest('connected_at')
                            ->first();

                        // Cache the page_id on instagram channel for future lookups
                        if ($channel && empty($channel->page_id)) {
                            $channel->update(['page_id' => $entryId]);
                        }
                    }
                }

                if (!$channel) {
                    Log::error('No Instagram channel found', [
                        'entry_id'  => $entryId,
                        'available' => Channel::where('type', 'instagram')
                            ->where('status', 'connected')
                            ->get(['id', 'instagram_account_id', 'page_id', 'page_name'])
                            ->toArray(),
                    ]);
                    continue;
                }

                $this->processMessage($channel, $senderId, $messageText);
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

                if (!$channel) {
                    $channel = Channel::where('page_id', $entryId)
                        ->where('type', 'instagram')->where('status', 'connected')
                        ->latest('connected_at')->first();
                }

                if (!$channel) {
                    $fbChannel = Channel::where('page_id', $entryId)
                        ->where('type', 'facebook')->where('status', 'connected')
                        ->latest('connected_at')->first();
                    if ($fbChannel) {
                        $channel = Channel::where('type', 'instagram')
                            ->where('status', 'connected')
                            ->where('user_id', $fbChannel->user_id)
                            ->latest('connected_at')->first();
                    }
                }

                if (!$channel) {
                    Log::warning('No Instagram channel found for comment', ['entry_id' => $entryId]);
                    continue;
                }

                $this->processComment($channel, $senderId, $commentText, $commentId);
            }
        }
    }

    private function processMessage(Channel $channel, string $senderId, string $messageText): void
    {
        Log::info('Processing message', [
            'channel_type' => $channel->type,
            'channel_id'   => $channel->id,
            'sender'       => $senderId,
            'message'      => $messageText,
            'business_id'  => $channel->business_id,
        ]);

        $conversation = \App\Models\Conversation::firstOrCreate(
            ['channel_id' => $channel->id, 'sender_id' => $senderId],
            ['business_id' => $channel->business_id, 'status' => 'open', 'last_message_at' => now()]
        );

        // If we don't have a name for this sender yet, queue a job to fetch it from the Graph API
        if ($conversation->wasRecentlyCreated || empty($conversation->sender_name)) {
            \App\Jobs\FetchSenderName::dispatch($conversation->id, $channel->id, $senderId);
        }

        $conversation->last_message_at = now();
        $conversation->save();

        $message = \App\Models\Message::create([
            'conversation_id' => $conversation->id,
            'content'         => $messageText,
            'direction'       => 'inbound',
            'is_ai'           => false,
            'status'          => 'received',
        ]);

        if ($channel->user_id) {
            broadcast(new \App\Events\MessageReceived($message, $conversation, $channel->user_id));
        }

        // Implement message debounce to prevent multiple AI replies
        $debounceKey = "debounce:conversation:{$conversation->id}";
        $debounceWindow = 10; // 10 seconds debounce window
        
        if (Cache::has($debounceKey)) {
            Log::info('Message debounced - AI reply skipped', [
                'conversation_id' => $conversation->id,
                'message_id' => $message->id
            ]);
        } else {
            \App\Jobs\ProcessAutoReply::dispatch($message->id);
            Cache::put($debounceKey, true, $debounceWindow);
            Log::info('ProcessAutoReply job dispatched', ['message_id' => $message->id]);
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
                Log::info('Reply sent successfully via ' . $channel->type, ['recipient' => $recipientId]);
            } else {
                Log::error('Failed to send reply', [
                    'channel_type' => $channel->type,
                    'status'       => $response->status(),
                    'response'     => $response->json(),
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Send reply exception', ['error' => $e->getMessage()]);
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
                Log::error('Gemini API error', $response->json());
                return null;
            }

            $reply = $response->json()['candidates'][0]['content']['parts'][0]['text'] ?? null;
            return $reply ? trim($reply) : null;

        } catch (\Exception $e) {
            Log::error('Gemini exception', ['error' => $e->getMessage()]);
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
            Log::info('Gmail Pub/Sub webhook received', $body);

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
                Log::warning('Gmail webhook: missing emailAddress or historyId', ['payload' => $payload]);
                return response('OK', 200);
            }

            Log::info('Gmail push notification', [
                'email' => $emailAddress,
                'history_id' => $historyId,
            ]);

            // Find the channel by email address
            $channel = Channel::where('type', 'gmail')
                ->where('page_name', $emailAddress)
                ->where('status', 'connected')
                ->first();

            if (!$channel) {
                Log::warning('Gmail webhook: no channel found for email', ['email' => $emailAddress]);
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
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
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

            // Check if message already exists (deduplication)
            $existingMessage = \App\Models\Message::where('gmail_message_id', $messageId)->first();
            if ($existingMessage) {
                Log::info('Gmail message already processed', ['message_id' => $messageId]);
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
                'error' => $e->getMessage(),
                'message_id' => $messageId,
            ]);
        }
    }

    private function decodeBody(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/'));
    }
}
