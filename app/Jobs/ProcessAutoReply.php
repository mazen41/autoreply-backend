<?php

namespace App\Jobs;

use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use App\Http\Controllers\GmailController;
use App\Services\EvolutionApiService;
use Google\Service\Gmail;
use Google\Service\Gmail\Message as GmailMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ProcessAutoReply implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $backoff = 30;

    public function __construct(public int $messageId)
    {
    }

    public function handle(): void
    {
        Log::info('ProcessAutoReply job started', ['message_id' => $this->messageId]);

        $message = Message::with(['conversation.channel', 'conversation.channel.business', 'conversation.channel.user'])
            ->find($this->messageId);

        if (!$message) {
            Log::warning('ProcessAutoReply: message not found', ['message_id' => $this->messageId]);
            return;
        }

        $channel = $message->conversation->channel;
        $conversation = $message->conversation;

        if (!$channel || !$channel->ai_enabled) {
            Log::info('ProcessAutoReply: AI not enabled for channel', ['channel_id' => $channel?->id]);
            return;
        }

        if (!$conversation || !$conversation->ai_enabled) {
            Log::info('ProcessAutoReply: AI not enabled for conversation', ['conversation_id' => $conversation?->id]);
            return;
        }

        if ($channel->status !== 'connected') {
            Log::warning('ProcessAutoReply: channel not connected', ['channel_id' => $channel->id, 'status' => $channel->status]);
            return;
        }

        // Check subscription limits
        $user = $channel->user;
        if (!$user) {
            Log::warning('ProcessAutoReply: channel has no user', ['channel_id' => $channel->id]);
            return;
        }

        $subscription = $user->activeSubscription;
        $package = $subscription ? $subscription->package : \App\Models\Package::where('name', 'Free')->first();

        if (!$package) {
            Log::error('ProcessAutoReply: no package found', ['user_id' => $user->id]);
            return;
        }

        // Count AI replies this month
        $aiRepliesThisMonth = Message::where('is_ai', true)
            ->where('created_at', '>=', now()->startOfMonth())
            ->whereHas('conversation.channel', function ($query) use ($user) {
                $query->where('user_id', $user->id);
            })
            ->count();

        // Check limit
        if ($package->ai_replies_limit !== -1 && $aiRepliesThisMonth >= $package->ai_replies_limit) {
            Log::info('ProcessAutoReply: AI replies limit reached', [
                'user_id' => $user->id,
                'limit' => $package->ai_replies_limit,
                'used' => $aiRepliesThisMonth
            ]);
            return;
        }

        // Build system prompt from business profile
        $systemPrompt = $this->buildSystemPrompt($channel);

        // Get last 10 messages for context
        $contextMessages = Message::where('conversation_id', $message->conversation_id)
            ->orderBy('created_at', 'desc')
            ->take(10)
            ->get()
            ->reverse()
            ->map(fn($m) => [
                'role' => $m->direction === 'inbound' ? 'user' : 'assistant',
                'content' => $m->content,
            ])
            ->toArray();

        $aiResponse = $this->callConfiguredAI($systemPrompt, $contextMessages);

        if (!$aiResponse) {
            Log::error('ProcessAutoReply: configured AI providers returned no response', ['message_id' => $this->messageId]);
            return;
        }

        // Save AI response as outbound message
        $replyMessage = Message::create([
            'conversation_id' => $message->conversation_id,
            'content' => $aiResponse,
            'direction' => 'outbound',
            'status' => 'auto',
            'is_ai' => true,
            'source' => 'ai',
            'send_status' => 'pending',
        ]);

        Log::info('ProcessAutoReply: AI reply saved', ['message_id' => $replyMessage->id]);

        if ($channel->user_id) {
            broadcast(new \App\Events\MessageReceived($replyMessage, $message->conversation, $channel->user_id));
        }

        // Send reply through platform
        $this->sendReply($channel, $message->conversation, $replyMessage);
    }

    private function buildSystemPrompt(Channel $channel): string
    {
        $business = $channel->business;

        if (!$business) {
            return "You are a helpful customer support agent. Reply concisely as a customer support agent for this business. Never mention you are AI. Reply in the same language the customer used (Arabic or English).";
        }

        $workingDays = is_array($business->working_days) ? implode(', ', $business->working_days) : ($business->working_days ?? 'N/A');
        $workingHours = "{$workingDays} from {$business->working_from} to {$business->working_to}";

        $faqsText = '';
        if (!empty($business->faqs)) {
            $faqs = is_array($business->faqs) ? $business->faqs : json_decode($business->faqs, true);
            if (is_array($faqs)) {
                foreach ($faqs as $faq) {
                    $q = $faq['question'] ?? $faq['q'] ?? '';
                    $a = $faq['answer'] ?? $faq['a'] ?? '';
                    if ($q && $a) $faqsText .= "Q: {$q}\nA: {$a}\n";
                }
            }
        }

        $prompt = "You are a customer support agent for the following business. Never mention you are AI.

";
        $prompt .= "Business name: {$business->business_name}\n";
        $prompt .= "Business type: {$business->business_type}\n";
        $prompt .= "Location: {$business->city}, {$business->country}\n";
        $prompt .= "Working hours: {$workingHours}\n";
        $prompt .= "Services/Products: {$business->services}\n";
        $prompt .= "Reply style: " . ($business->reply_style ?? 'friendly and professional') . "\n";

        if ($faqsText) {
            $prompt .= "\nCommon questions and answers:\n{$faqsText}\n";
        }

        if (!empty($business->knowledge_base)) {
            $prompt .= "\nAdditional business knowledge and documentation:\n{$business->knowledge_base}\n";
        }

        $prompt .= "\nRules:\n";
        $prompt .= "- Only use the information provided above. Never make things up.\n";
        $prompt .= "- If you don't know the answer, politely say you will follow up and ask for their contact.\n";
        $prompt .= "- Reply in the same language the customer used (Arabic or English).\n";
        $prompt .= "- Keep replies short, clear, and friendly.\n";
        $prompt .= "- Never mention you are an AI or a bot.\n";

        return $prompt;
    }

    private function callConfiguredAI(string $systemPrompt, array $contextMessages): ?string
    {
        $primary = config('services.ai.provider', 'gemini');
        $fallback = config('services.ai.fallback_provider', $primary === 'gemini' ? 'claude' : 'gemini');
        $providers = array_values(array_unique(array_filter([$primary, $fallback])));

        foreach ($providers as $provider) {
            $reply = match ($provider) {
                'claude' => $this->callClaudeAPI($systemPrompt, $contextMessages),
                'gemini' => $this->callGeminiAPI($systemPrompt, $contextMessages),
                default => null,
            };

            if ($reply) {
                Log::info('ProcessAutoReply: AI provider succeeded', ['provider' => $provider]);
                return $reply;
            }

            Log::warning('ProcessAutoReply: AI provider failed, checking fallback', ['provider' => $provider]);
        }

        return null;
    }
    private function callClaudeAPI(string $systemPrompt, array $contextMessages): ?string
    {
        $apiKey = config('services.claude.api_key');
        if (!$apiKey) {
            Log::error('ProcessAutoReply: ANTHROPIC_API_KEY not set');
            return null;
        }

        try {
            $response = Http::timeout((int) config('services.ai.timeout', 30))
                ->withHeaders([
                    'x-api-key' => $apiKey,
                    'anthropic-version' => '2023-06-01',
                    'content-type' => 'application/json',
                ])
                ->post('https://api.anthropic.com/v1/messages', [
                    'model' => config('services.claude.model', 'claude-haiku-4-5-20251001'),
                    'max_tokens' => (int) config('services.ai.max_tokens', 500),
                    'system' => $systemPrompt,
                    'messages' => $contextMessages,
                ]);

            if (!$response->successful()) {
                Log::error('ProcessAutoReply: Claude API error', [
                    'status' => $response->status(),
                    'body' => $response->json(),
                ]);
                return null;
            }

            $content = $response->json('content')[0]['text'] ?? null;
            return $content ? trim($content) : null;

        } catch (\Exception $e) {
            Log::error('ProcessAutoReply: Claude API exception', ['error' => $e->getMessage()]);
            return null;
        }
    }

    private function callGeminiAPI(string $systemPrompt, array $contextMessages): ?string
    {
        $apiKey = config('services.gemini.api_key');
        if (!$apiKey) {
            Log::error('ProcessAutoReply: GEMINI_API_KEY not set');
            return null;
        }

        $models = array_values(array_unique(array_filter([
            config('services.gemini.model', 'gemini-2.5-flash'),
            'gemini-2.5-flash',
            'gemini-2.0-flash-exp',
            'gemini-1.5-flash',
            'gemini-1.5-pro',
        ])));

        // Convert context to Gemini format
        $contents = [];
        foreach ($contextMessages as $msg) {
            $contents[] = [
                'role' => $msg['role'] === 'assistant' ? 'model' : 'user',
                'parts' => [['text' => $msg['content']]],
            ];
        }

        // Prepend system prompt as first user message
        array_unshift($contents, [
            'role' => 'user',
            'parts' => [['text' => "System instructions: " . $systemPrompt]],
        ]);

        foreach ($models as $model) {
            try {
                $response = Http::timeout((int) config('services.ai.timeout', 30))
                    ->withOptions(['verify' => false])
                    ->post(
                        "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}",
                        [
                            'contents' => $contents,
                            'generationConfig' => [
                                'maxOutputTokens' => (int) config('services.ai.max_tokens', 500),
                                'temperature' => (float) config('services.ai.temperature', 0.7),
                            ],
                        ]
                    );

                if ($response->successful()) {
                    $reply = $response->json('candidates')[0]['content']['parts'][0]['text'] ?? null;
                    if ($reply) {
                        Log::info('ProcessAutoReply: Gemini success', ['model' => $model]);
                        return trim($reply);
                    }
                }

                Log::warning('ProcessAutoReply: Gemini model failed', [
                    'model' => $model,
                    'status' => $response->status(),
                    'body' => $response->json(),
                ]);

            } catch (\Exception $e) {
                Log::warning('ProcessAutoReply: Gemini model exception', [
                    'model' => $model,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::error('ProcessAutoReply: All Gemini models failed');
        return null;
    }

    private function sendReply(Channel $channel, Conversation $conversation, Message $replyMessage): void
    {
        $senderId = $conversation->sender_id;
        $content = $replyMessage->content;

        try {
            $success = false;

            if ($channel->type === 'facebook') {
                $success = $this->sendFacebookReply($channel, $senderId, $content);
            } elseif ($channel->type === 'instagram') {
                $success = $this->sendInstagramReply($channel, $senderId, $content);
            } elseif ($channel->type === 'gmail') {
                $success = $this->sendGmailReply($channel, $conversation, $content);
            } elseif ($channel->type === 'whatsapp') {
                $success = $this->sendWhatsAppReply($channel, $senderId, $content);
            }

            if ($success) {
                $replyMessage->update(['send_status' => 'sent']);
                Log::info('ProcessAutoReply: reply sent successfully', [
                    'platform' => $channel->type,
                    'message_id' => $replyMessage->id,
                ]);
            } else {
                $replyMessage->update(['send_status' => 'failed']);
                Log::error('ProcessAutoReply: reply send failed', [
                    'platform' => $channel->type,
                    'message_id' => $replyMessage->id,
                ]);
            }

        } catch (\Exception $e) {
            $replyMessage->update(['send_status' => 'failed']);
            Log::error('ProcessAutoReply: send reply exception', [
                'error' => $e->getMessage(),
                'platform' => $channel->type,
            ]);
        }
    }

    private function sendFacebookReply(Channel $channel, string $recipientId, string $message): bool
    {
        $accessToken = decrypt($channel->getRawOriginal('access_token'));
        $url = "https://graph.facebook.com/v19.0/me/messages?access_token={$accessToken}";

        $response = Http::timeout(10)
            ->withOptions(['verify' => false])
            ->post($url, [
                'recipient' => ['id' => $recipientId],
                'message' => ['text' => $message],
            ]);

        return $response->successful();
    }

    private function sendInstagramReply(Channel $channel, string $recipientId, string $message): bool
    {
        // Instagram uses the same API as Facebook with the page access token
        return $this->sendFacebookReply($channel, $recipientId, $message);
    }

    private function sendGmailReply(Channel $channel, Conversation $conversation, string $body): bool
    {
        $gmailCtrl = new GmailController();
        $client = $gmailCtrl->getAuthenticatedClient($channel);

        if (!$client) {
            Log::error('ProcessAutoReply: could not get Gmail client', ['channel_id' => $channel->id]);
            return false;
        }

        try {
            $gmail = new Gmail($client);
            $to = $conversation->sender_email ?? 'unknown';
            $subject = $conversation->subject ?? 'Re: Your message';
            $threadId = $conversation->sender_id; // sender_id stores threadId for Gmail

            // Get the original message Gmail-header Message-ID for threading
            $originalMessage = Message::where('conversation_id', $conversation->id)
                ->where('direction', 'inbound')
                ->whereNotNull('gmail_message_id')
                ->orderBy('created_at', 'asc')
                ->first();

            $inReplyToId = $originalMessage?->gmail_message_id ?? '';

            $raw = "To: {$to}\r\n";
            $raw .= "Subject: Re: {$subject}\r\n";
            if ($inReplyToId) {
                $raw .= "In-Reply-To: {$inReplyToId}\r\n";
                $raw .= "References: {$inReplyToId}\r\n";
            }
            $raw .= "Content-Type: text/plain; charset=utf-8\r\n\r\n";
            $raw .= $body;

            $encoded = rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');

            $message = new GmailMessage();
            $message->setRaw($encoded);
            if ($threadId) {
                $message->setThreadId($threadId);
            }

            $gmail->users_messages->send('me', $message);
            return true;

        } catch (\Exception $e) {
            Log::error('ProcessAutoReply: Gmail send exception', ['error' => $e->getMessage()]);
            return false;
        }
    }

    private function sendWhatsAppReply(Channel $channel, string $recipientId, string $message): bool
    {
        try {
            $whatsappService = new EvolutionApiService();
            $instanceName = $channel->page_id; // We store instance_name in page_id for WhatsApp

            $response = $whatsappService->sendTextMessage($instanceName, $recipientId, $message);

            if (isset($response['key']['id'])) {
                // Also save to WhatsApp messages table for legacy compatibility
                \App\Models\WhatsAppMessage::create([
                    'whatsapp_instance_id' => \App\Models\WhatsAppInstance::where('instance_name', $instanceName)->first()?->id,
                    'user_id' => $channel->user_id,
                    'message_id' => $response['key']['id'] ?? null,
                    'remote_message_id' => $response['key']['id'] ?? null,
                    'direction' => 'outgoing',
                    'from_phone' => null,
                    'from_name' => null,
                    'to_phone' => $recipientId,
                    'body' => $message,
                    'message_type' => 'text',
                    'media' => null,
                    'metadata' => ['evolution_response' => $response],
                    'status' => 'sent',
                    'sent_at' => now(),
                ]);

                return true;
            }

            return false;

        } catch (\Exception $e) {
            Log::error('ProcessAutoReply: WhatsApp send exception', ['error' => $e->getMessage()]);
            return false;
        }
    }
}



