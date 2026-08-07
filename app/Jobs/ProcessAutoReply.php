<?php

namespace App\Jobs;

use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use App\Http\Controllers\GmailController;
use App\Services\EvolutionApiService;
use App\Services\KnowledgeChunker;
use App\Services\AICapabilitiesService;
use App\Services\SallaService;
use Google\Service\Gmail;
use Google\Service\Gmail\Message as GmailMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

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

        // Check subscription limits using cached counter
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

        // Use cached counter for AI replies limit check
        $cacheKey = "user_{$user->id}_ai_replies_" . now()->format('Y-m');
        $aiRepliesThisMonth = cache()->remember($cacheKey, now()->endOfMonth(), function () use ($user) {
            return Message::where('is_ai', true)
                ->where('created_at', '>=', now()->startOfMonth())
                ->whereHas('conversation.channel', function ($query) use ($user) {
                    $query->where('user_id', $user->id);
                })
                ->count();
        });

        // Check limit BEFORE incrementing — increment only after message is actually sent
        if ($package->ai_replies_limit !== -1 && $aiRepliesThisMonth >= $package->ai_replies_limit) {
            Log::info('ProcessAutoReply: AI replies limit reached', [
                'user_id' => $user->id,
                'limit' => $package->ai_replies_limit,
                'used' => $aiRepliesThisMonth
            ]);
            return;
        }

        $detectedLanguage = $this->detectLanguage($message->content);
        
        // Build system prompt from business profile
        $systemPrompt = $this->buildSystemPrompt($channel, $message->content, $detectedLanguage);

        // Get last 10 messages for context
        $contextMessages = Message::where('conversation_id', $message->conversation_id)
            ->orderBy('created_at', 'desc')
            ->take(10)
            ->get()
            ->reverse()
            ->map(fn($m) => [
                'role'        => $m->direction === 'inbound' ? 'user' : 'assistant',
                'direction'   => $m->direction,
                'content'     => $m->content,
                'is_ai'       => $m->is_ai,
                'send_status' => $m->send_status,
            ])
            ->toArray();

        // Detect if handoff is needed
        $handoffDetection = AICapabilitiesService::detectHandoff($message->content, $contextMessages);
        if ($handoffDetection['should_escalate']) {
            Log::info('ProcessAutoReply: Conversation escalated to human', [
                'conversation_id' => $conversation->id,
                'reasons' => $handoffDetection['reasons'],
                'confidence' => $handoffDetection['confidence']
            ]);

            // Flag conversation for human review
            $conversation->update([
                'requires_human' => true,
                'escalated_at' => now(),
                'escalation_reason' => implode(', ', $handoffDetection['reasons']),
                'escalation_notified' => false
            ]);

            // Notify business owner about escalation
            // This would trigger a notification system
            return;
        }

        // Check business hours routing
        $businessHoursCheck = AICapabilitiesService::checkBusinessHours($channel->business ?? null);
        if ($businessHoursCheck['should_use_hours_routing']) {
            Log::info('ProcessAutoReply: Business hours routing activated', [
                'conversation_id' => $conversation->id,
                'is_after_hours' => $businessHoursCheck['is_after_hours']
            ]);

            // Send after-hours message and queue for human review
            $afterHoursMessage = $businessHoursCheck['routing_message'];
            $replyMessage = Message::create([
                'conversation_id' => $message->conversation_id,
                'content' => $afterHoursMessage,
                'direction' => 'outbound',
                'status' => 'auto',
                'is_ai' => true,
                'source' => 'ai_hours_routing',
                'send_status' => 'pending',
            ]);

            $conversation->update([
                'requires_human' => true,
                'escalated_at' => now(),
                'escalation_reason' => 'after_hours'
            ]);

            $this->sendReply($channel, $conversation, $replyMessage);
            return;
        }

        // Apply tone/persona customization
        $toneStyle = $channel->business?->ai_tone_style ?? [
            'tone' => 'friendly',
            'formality' => 'casual',
            'focus' => 'support'
        ];
        
        // Check if this is an order-related query and user has Salla connected
        $sallaContext = $this->getSallaOrderContext($message, $channel);
        if ($sallaContext) {
            $systemPrompt .= "\n\n### ORDER CONTEXT ###\n" . $sallaContext;
        }
        
        $systemPrompt = AICapabilitiesService::applyTonePersona($systemPrompt, $toneStyle, $detectedLanguage);

        // Generate the AI reply using the full business-aware system prompt + conversation history
        $aiResponse = $this->callConfiguredAI($systemPrompt, $contextMessages);

        if (!$aiResponse) {
            Log::error('ProcessAutoReply: configured AI providers returned no response', ['message_id' => $this->messageId]);
            return;
        }

        // Score the reply for confidence — pass the actual reply so scoring is accurate
        $context = [
            'business'       => $channel->business,
            'business_name'  => $channel->business?->business_name ?? null,
            'language'       => $detectedLanguage,
            'has_store_data' => !empty($sallaContext),
            'has_order_data' => !empty($sallaContext),
        ];

        $confidenceResult = AICapabilitiesService::scoreReply(
            $message->content,
            $aiResponse,
            $contextMessages,
            $context
        );

        $confidenceScore = $confidenceResult['confidence'];
        $intent          = $confidenceResult['intent'];
        $intentConf      = $confidenceResult['intent_confidence'];

        $confidenceThreshold = $channel->business?->ai_confidence_threshold ?? 70;

        // If confidence is below threshold, escalate to human
        if ($confidenceScore < $confidenceThreshold) {
            Log::info('ProcessAutoReply: AI confidence below threshold, escalating to human', [
                'conversation_id' => $conversation->id,
                'confidence'      => $confidenceScore,
                'threshold'       => $confidenceThreshold,
                'intent'          => $intent,
                'issues'          => $confidenceResult['issues'],
            ]);

            $conversation->update([
                'requires_human' => true,
                'escalated_at' => now(),
                'escalation_reason' => "low_confidence: {$confidenceScore}% (intent: {$intent})"
            ]);

            return;
        }

        // Auto-tag conversation based on AI-detected intent
        if ($intent !== 'unknown') {
            \App\Models\ConversationTag::firstOrCreate(
                ['conversation_id' => $conversation->id, 'tag' => $intent],
                ['intent' => $intent, 'confidence' => $intentConf * 100]
            );
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

        // Increment the monthly counter now that the message is confirmed saved
        cache()->increment($cacheKey);

        if ($channel->user_id) {
            broadcast(new \App\Events\MessageReceived($replyMessage, $message->conversation, $channel->user_id));
        }

        // Send reply through platform
        $this->sendReply($channel, $message->conversation, $replyMessage);
    }

    private function buildSystemPrompt(Channel $channel, string $currentMessage = '', string $language = 'english'): string
    {
        $business = $channel->business;

        // Fallback: If channel is missing business_id, try to find the user's business profile
        if (!$business && $channel->user_id) {
            $business = \App\Models\BusinessProfile::where('user_id', $channel->user_id)->first();
        }

        // Hard language instruction at the very top — Gemini respects this when placed first
        $langInstruction = $language === 'arabic'
            ? "CRITICAL: You MUST reply in Arabic only. Do not use English under any circumstances.\n\n"
            : "CRITICAL: You MUST reply in English only. Do not use Arabic under any circumstances.\n\n";

        if (!$business) {
            return $langInstruction . "You are an AI customer support assistant. Answer questions truthfully. If you do not know the answer, politely state that you don't know and offer to connect them with a human agent.";
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

        // Build knowledge base from individual files with proper chunking
        $knowledgeText = '';
        foreach ($business->knowledgeFiles()->get() as $file) {
            $fullText = $file->extracted_text;
            
            // Chunk the file content to preserve sentence boundaries
            if (strlen($fullText) > 2000) {
                $chunks = KnowledgeChunker::chunkText($fullText, 2000, 200);
                
                // Get relevant chunks based on the user's message
                $relevantChunks = KnowledgeChunker::getRelevantChunks(
                    $chunks,
                    $currentMessage,
                    3
                );
                
                $knowledgeText .= "\n\n--- File: {$file->filename} (Relevant Chunks) ---\n";
                $knowledgeText .= KnowledgeChunker::formatChunksForPrompt($relevantChunks, $file->filename);
            } else {
                $knowledgeText .= "\n\n--- File: {$file->filename} ---\n";
                $knowledgeText .= $fullText;
            }
        }

        $prompt = $langInstruction;
        $prompt .= "You are the AI assistant for {$business->business_name}, a {$business->business_type} business.\n";
        $prompt .= "Your job is to answer customer questions accurately using ONLY the information provided below.\n\n";

        $prompt .= "### BUSINESS INFORMATION ###\n";
        $prompt .= "- Business Name: {$business->business_name}\n";
        $prompt .= "- Business Type: {$business->business_type}\n";
        $prompt .= "- Location: {$business->city}, {$business->country}\n";
        $prompt .= "- Contact Phone: {$business->phone}\n";
        $prompt .= "- Working Hours: {$workingHours}\n";
        $prompt .= "- Services/Products: {$business->services}\n";
        
        if ($faqsText) {
            $prompt .= "\n### FREQUENTLY ASKED QUESTIONS ###\n{$faqsText}\n";
        }

        // Add knowledge base from uploaded files
        if (!empty($knowledgeText)) {
            $prompt .= "\n### KNOWLEDGE BASE & DOCUMENTATION ###\n{$knowledgeText}\n";
        }

        // Add custom AI instructions
        if (!empty($business->ai_instructions)) {
            $prompt .= "\n### CUSTOM INSTRUCTIONS ###\n{$business->ai_instructions}\n";
        }

        $prompt .= "\n### CRITICAL RULES ###\n";
        $prompt .= "1. NEVER say vague filler like 'I am here to assist you with any questions' as a substitute for a real answer.\n";
        $prompt .= "2. If you do not know the answer based on the provided information, DO NOT guess or make things up. Honestly say you don't have that information and offer to have a human follow up.\n";
        $prompt .= "3. Actively use the conversation history context provided. Do not repeat or contradict yourself.\n";
        $prompt .= "4. Keep replies concise and under 3 sentences where possible — never write long paragraphs.\n";
        $prompt .= "5. Reply in the same language the customer used (Arabic or English).\n";
        $prompt .= "6. Reply style should be: " . ($business->reply_style ?? 'friendly and professional') . ".\n";

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
            config('services.gemini.model', 'gemini-3.5-flash'),
            'gemini-3.5-flash',
            'gemini-3.5-flash-lite',
            'gemini-flash-latest',
            'gemini-flash-lite-latest',
        ])));

        // Convert context to Gemini format
        $contents = [];
        $lastRole = null;
        
        foreach ($contextMessages as $msg) {
            $role = $msg['role'] === 'assistant' ? 'model' : 'user';
            
            // Merge consecutive messages of the same role (Gemini requires strict alternating roles)
            if ($role === $lastRole && !empty($contents)) {
                $lastIndex = count($contents) - 1;
                $contents[$lastIndex]['parts'][0]['text'] .= "\n\n" . $msg['content'];
            } else {
                $contents[] = [
                    'role' => $role,
                    'parts' => [['text' => $msg['content']]],
                ];
            }
            $lastRole = $role;
        }

        foreach ($models as $model) {
            try {
                $postData = [
                    'contents' => $contents,
                    'generationConfig' => [
                        'maxOutputTokens' => (int) config('services.ai.max_tokens', 1000),
                        'temperature' => 0.4,
                    ],
                ];

                if (!empty($systemPrompt)) {
                    $postData['systemInstruction'] = [
                        'parts' => [['text' => $systemPrompt]]
                    ];
                }

                $response = Http::timeout((int) config('services.ai.timeout', 30))
                    ->withOptions(['verify' => false])
                    ->post(
                        "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}",
                        $postData
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
        // The Channel model's accessor already decrypts access_token — do NOT
        // call decrypt() again or the token will be double-decrypted and corrupted.
        $accessToken = $channel->access_token;
        $url = "https://graph.facebook.com/v19.0/me/messages?access_token={$accessToken}";

        $response = Http::timeout(10)
            ->withOptions(['verify' => false])
            ->post($url, [
                'recipient' => ['id' => $recipientId],
                'message' => ['text' => $message],
            ]);

        if (!$response->successful()) {
            Log::error('ProcessAutoReply: Facebook send failed', [
                'status' => $response->status(),
                'body' => $response->json(),
                'recipient' => $recipientId,
            ]);
        }

        return $response->successful();
    }

    /**
     * Detect language of message (simple heuristic)
     */
    private function detectLanguage(string $text): string
    {
        // Arabic character detection
        if (preg_match('/[\x{0600}-\x{06FF}]/u', $text)) {
            return 'arabic';
        }
        
        // Default to English
        return 'english';
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

    /**
     * Get Salla order context if message is order-related and user has Salla connected
     */
    private function getSallaOrderContext(Message $message, Channel $currentChannel): ?string
    {
        // Check if message contains order-related keywords in Arabic
        $orderKeywords = [
            'فين طلبي', 'حالة الطلب', 'متى يوصل', 'أبغى أعرف طلبي',
            'طلبي', 'الطلب', 'شحن', 'توصيل', 'استلم'
        ];

        $messageLower = strtolower($message->content);
        $isOrderRelated = false;
        foreach ($orderKeywords as $keyword) {
            if (str_contains($messageLower, $keyword)) {
                $isOrderRelated = true;
                break;
            }
        }

        if (!$isOrderRelated) {
            return null;
        }

        // Find Salla channel for this user
        $sallaChannel = Channel::where('user_id', $currentChannel->user_id)
            ->where('type', 'salla')
            ->where('status', 'connected')
            ->first();

        if (!$sallaChannel) {
            Log::info('User does not have connected Salla channel');
            return null;
        }

        try {
            $sallaService = new SallaService();
            
            // Extract phone number from conversation sender
            $phone = $this->extractPhoneNumber($message);
            if (!$phone) {
                Log::info('Could not extract phone number for Salla lookup');
                return null;
            }

            // Get latest order for this phone number
            $order = $sallaService->getLatestOrderByPhone($sallaChannel->access_token, $phone);
            
            if ($order) {
                Log::info('Found Salla order for customer', [
                    'order_id' => $order['id'] ?? null,
                    'phone' => $phone,
                ]);
                return $sallaService->formatOrderForAI($order);
            }

            Log::info('No Salla order found for phone number', ['phone' => $phone]);
            return null;

        } catch (\Exception $e) {
            Log::error('Error getting Salla order context', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Extract phone number from message or conversation
     */
    private function extractPhoneNumber(Message $message): ?string
    {
        // Try to get from conversation sender
        $conversation = $message->conversation;
        
        // Check different possible phone number fields
        $phone = $conversation->sender_phone ?? 
                 $conversation->sender_id ?? // For WhatsApp, sender_id might be phone
                 null;

        if ($phone) {
            // Clean phone number - remove non-numeric characters
            $phone = preg_replace('/[^0-9]/', '', $phone);
            
            // Ensure it starts with country code if needed
            if (strlen($phone) === 10 && str_starts_with($phone, '0')) {
                $phone = '966' . substr($phone, 1); // Convert Saudi format
            }
            
            return $phone;
        }

        return null;
    }
}



