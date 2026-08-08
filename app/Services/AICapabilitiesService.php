<?php

namespace App\Services;

use App\Models\BusinessProfile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class AICapabilitiesService
{
    private static function getAIProvider(): string
    {
        return env('AI_PROVIDER', 'gemini');
    }

    private static function getAIModel(): string
    {
        return env('GEMINI_MODEL', 'gemini-2.5-flash');
    }

    private static function getAIAPIKey(): string
    {
        return env('GEMINI_API_KEY', '');
    }

    /**
     * Ultimate Master System Prompt with JSON Output
     *
     * This prompt handles ALL conversation intents:
     *   greeting, order_status, place_order (product browsing + checkout), question, escalation
     *
     * The system (ProcessAutoReply) injects data before calling the AI.
     * The AI only replies — it never fetches data.
     */
    private static function getUltimateSystemPrompt(array $context = []): string
    {
        $businessName   = $context['business_name'] ?? 'our business';
        $platform       = $context['platform']      ?? 'whatsapp';
        $language       = $context['language']      ?? 'english';
        $hasKnowledgeBase = !empty($context['knowledge_base']);
        $hasOrderData     = !empty($context['order_data']);
        $hasProducts      = !empty($context['products']);
        $hasCartState     = !empty($context['cart']);

        $langRule = $language === 'arabic'
            ? "CRITICAL: You MUST reply in Arabic only. Do not use English.\n\n"
            : "CRITICAL: You MUST reply in English only. Do not use Arabic.\n\n";

        $prompt = $langRule;

        $prompt .= "You are the AI customer support and sales assistant for {$businessName}.

You ONLY generate replies using the data provided to you.
You DO NOT fetch data, call APIs, or guess missing information.

==========================================================
INTENTS YOU HANDLE
==========================================================

1. greeting           — hi, hello, مرحبا, السلام عليكم
2. question           — general question about the business
3. order_status       — asking about an existing order, shipping, tracking
4. place_order        — wants to BUY something / browse products / add to cart
5. escalation         — angry, frustrated, explicitly asks for human/agent

==========================================================
1. GREETING
==========================================================

Trigger: hi / hello / hey / مرحبا / السلام عليكم

Reply:
\"Hi 👋 Welcome to {$businessName}! How can I help you today?\"

intent = greeting

==========================================================
2. GENERAL QUESTIONS
==========================================================

";

        if ($hasKnowledgeBase) {
            $prompt .= "KNOWLEDGE BASE:\n" . $context['knowledge_base'] . "\n\n";
            $prompt .= "If the answer exists in the knowledge base → answer it clearly.\n\n";
        }

        $prompt .= "If you cannot answer → reply:
\"I couldn't find the exact information, but I'll forward this to our team and they'll get back to you shortly 😊\"
needs_escalation = true, intent = escalation

==========================================================
3. ORDER STATUS (Existing Orders)
==========================================================

Trigger: asking about order status, shipping, delivery, tracking, \"where is my order\", \"wein talbati\"

";

        if ($hasOrderData) {
            $orderData   = $context['order_data'];
            $orderNum    = $orderData['order_number']    ?? $orderData['id']              ?? 'N/A';
            $status      = $orderData['status']          ?? 'N/A';
            $shipping    = $orderData['shipping_status'] ?? 'Not available';
            $delivery    = $orderData['delivery_date']   ?? 'Not specified';
            $total       = $orderData['total']           ?? '';
            $currency    = $orderData['currency']        ?? '';
            $items       = $orderData['items']           ?? '';

            $prompt .= "ORDER DATA PROVIDED:
• Order Number: {$orderNum}
• Status: {$status}
• Shipping Status: {$shipping}
• Estimated Delivery: {$delivery}
• Total: {$total} {$currency}
• Items: {$items}

Reply with these exact details clearly formatted.
intent = order_status

";
        } else {
            $prompt .= "No order data was found for this customer automatically.

If customer asks about order status:
- If on WhatsApp (phone known): reply \"We couldn't find an order linked to your number. Could you share your order number? 📦\"
- Otherwise: reply \"Please share your order number and we'll look it up right away 📦\"

intent = order_status, needs_escalation = false

==========================================================
4. PLACE AN ORDER (Product Browsing + Ordering)
==========================================================

Trigger: \"I want to order\", \"place an order\", \"buy\", \"purchase\", \"show me products\",
         \"what do you sell\", \"عايز اطلب\", \"ابي اشتري\", \"المنتجات\"

";
        }

        if ($hasProducts) {
            $productList = '';
            foreach (($context['products'] ?? []) as $i => $p) {
                $num         = $i + 1;
                $name        = $p['name']    ?? 'Product';
                $price       = $p['price']   ?? '?';
                $currency    = $p['currency'] ?? 'SAR';
                $available   = ($p['available'] ?? true) ? '✅' : '❌ Out of stock';
                $productList .= "{$num}. {$name} — {$price} {$currency} {$available}\n";
            }

            $prompt .= "
AVAILABLE PRODUCTS:
{$productList}

When customer wants to order:
Step 1: Show the product list above and ask which product they want.
Step 2: When they choose → confirm: \"Great choice! 🎉 Here is how to complete your purchase: [store link or checkout instructions]\"

intent = place_order

";
        } else {
            $prompt .= "
No product catalogue is currently loaded.

If customer wants to buy:
→ Reply: \"I'd love to help you place an order! Let me connect you with our team who can assist you directly 😊\"
→ needs_escalation = true, intent = place_order

";
        }

        if ($hasCartState) {
            $prompt .= "CART STATE:\n" . json_encode($context['cart'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";
        }

        $prompt .= "
==========================================================
5. ESCALATION
==========================================================

Trigger ONLY when:
- Customer explicitly asks for human / agent / موظف / شخص / خدمة عملاء
- Customer is clearly angry or abusive
- You truly cannot answer after checking all data

NEVER escalate just because the customer asks about an order or wants to buy something.
Ordering and order status inquiries are handled by you — NOT by a human agent.

Escalation reply:
\"Sure 👍 I'm connecting you with a human agent now. Please wait a moment.\"
intent = escalation, needs_escalation = true

==========================================================
OUTPUT FORMAT (STRICT JSON — NO MARKDOWN — NO EXTRA TEXT)
==========================================================

{
  \"reply\": \"your message to the customer\",
  \"intent\": \"greeting | question | order_status | place_order | escalation\",
  \"needs_escalation\": true or false,
  \"confidence\": 0.0 to 1.0
}
";

        return $prompt;
    }

    /**
     * Hard Escalation Override (Pre-AI Check)
     */
    public static function checkHardEscalation(string $message): array
    {
        $messageLower = strtolower(trim($message));
        $escalationKeywords = [
            'human', 'agent', 'support', 'person', 'representative',
            'موظف', 'انسان', 'شخص', 'دعم', 'خدمة عملاء', 'كلم انسان'
        ];

        foreach ($escalationKeywords as $keyword) {
            if (str_contains($messageLower, $keyword)) {
                return [
                    'force_escalation' => true,
                    'matched_keyword' => $keyword,
                    'reason' => 'hard_keyword_override'
                ];
            }
        }

        return ['force_escalation' => false];
    }

    /**
     * Language Detection
     */
    public static function detectLanguage(string $message): array
    {
        $arabicChars = preg_match_all('/[\u0600-\u06FF]/u', $message, $matches);
        $englishChars = preg_match_all('/[a-zA-Z]/', $message, $matches);
        $totalChars = $arabicChars + $englishChars;

        if ($totalChars === 0) {
            return ['language' => 'english', 'confidence' => 0.5];
        }

        $arabicRatio = $arabicChars / $totalChars;
        $englishRatio = $englishChars / $totalChars;

        if ($arabicRatio > 0.7) {
            return ['language' => 'arabic', 'confidence' => 0.95];
        } elseif ($englishRatio > 0.7) {
            return ['language' => 'english', 'confidence' => 0.95];
        } else {
            return ['language' => 'mixed', 'confidence' => 0.7];
        }
    }

    /**
     * Rate Limiting Check
     */
    public static function checkRateLimit(string $userId, string $tenantId = 'default'): array
    {
        $redisKey = "rate_limit:{$tenantId}:{$userId}";
        $windowSeconds = 60;
        $maxRequests = 10;

        try {
            $currentCount = Redis::get($redisKey);

            if ($currentCount === null) {
                Redis::setex($redisKey, $windowSeconds, 1);
                return [
                    'rate_limited' => false,
                    'remaining' => $maxRequests - 1,
                    'reset_in' => $windowSeconds
                ];
            }

            $count = (int)$currentCount;

            if ($count >= $maxRequests) {
                $ttl = Redis::ttl($redisKey);
                return [
                    'rate_limited' => true,
                    'reason' => 'too_many_requests',
                    'limit' => $maxRequests,
                    'window' => $windowSeconds,
                    'reset_in' => $ttl,
                    'retry_after' => $ttl
                ];
            }

            Redis::incr($redisKey);

            return [
                'rate_limited' => false,
                'remaining' => $maxRequests - ($count + 1),
                'reset_in' => Redis::ttl($redisKey)
            ];

        } catch (\Exception $e) {
            Log::error('Rate limiting failed', ['error' => $e->getMessage()]);
            return ['rate_limited' => false, 'remaining' => $maxRequests];
        }
    }

    /**
     * Conversation Memory Check
     */
    public static function getConversationMemory(string $conversationId): array
    {
        $redisKey = "conversation:{$conversationId}:state";

        try {
            $existingState = Redis::get($redisKey);

            if ($existingState) {
                return json_decode($existingState, true);
            }

            return [
                'phone_provided' => false,
                'order_number_provided' => false,
                'last_asked' => null,
                'attempts' => 0
            ];

        } catch (\Exception $e) {
            Log::error('Conversation memory check failed', ['error' => $e->getMessage()]);
            return [
                'phone_provided' => false,
                'order_number_provided' => false,
                'last_asked' => null,
                'attempts' => 0
            ];
        }
    }

    /**
     * Update Conversation Memory
     */
    public static function updateConversationMemory(string $conversationId, array $updates): void
    {
        $redisKey = "conversation:{$conversationId}:state";

        try {
            $currentState = self::getConversationMemory($conversationId);
            $newState = array_merge($currentState, $updates, [
                'updated_at' => now()->toISOString()
            ]);

            Redis::setex($redisKey, 3600, json_encode($newState));

        } catch (\Exception $e) {
            Log::error('Conversation memory update failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Response Validation
     *
     * NOTE: The AI is instructed to return JSON, so we validate the *parsed
     * reply string* (not the raw JSON wrapper).  Previously this validator
     * was rejecting every valid AI response because it checked for `{`/`}`
     * in the raw JSON output.  We now receive the already-decoded reply text
     * and validate only that.
     */
    public static function validateResponse(string $response, string $intent): array
    {
        $validation = [
            'valid'    => true,
            'reasons'  => [],
            'warnings' => [],
        ];

        if (empty(trim($response))) {
            $validation['valid']     = false;
            $validation['reasons'][] = 'empty_response';
            return $validation;
        }

        if (strlen($response) > 500) {
            $validation['warnings'][] = 'Response length: ' . strlen($response) . ' chars (over 500)';
            // Do NOT fail — just warn; long but valid replies are better than fallback
        }

        $lowerResponse = mb_strtolower($response);

        $uncertainPhrases = ['i think', 'maybe', 'possibly', 'might be', 'ربما', 'قد يكون', 'أعتقد'];
        foreach ($uncertainPhrases as $phrase) {
            if (str_contains($lowerResponse, $phrase)) {
                $validation['warnings'][] = 'uncertain_language: ' . $phrase;
                // Warn only — do not fail; uncertain phrasing is preferable to fallback
                break;
            }
        }

        $systemKeywords = ['system prompt', 'instructions', 'rules', 'ai assistant', 'as an ai'];
        foreach ($systemKeywords as $keyword) {
            if (str_contains($lowerResponse, $keyword)) {
                $validation['valid']     = false;
                $validation['reasons'][] = 'system_instruction_leak';
                break;
            }
        }

        return $validation;
    }

    /**
     * Main AI Call with JSON Output
     */
    public static function callAIWithJSON(string $message, array $context = []): array
    {
        try {
            $systemPrompt = self::getUltimateSystemPrompt($context);

            $messages = [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user',   'content' => $message],
            ];

            $response = self::callAIChatWithRetry($messages);

            // Strip markdown code fences Gemini sometimes wraps around JSON
            // e.g.  ```json\n{...}\n```  →  {...}
            $trimmedResponse = trim($response);
            $trimmedResponse = preg_replace('/^```(?:json)?\s*/i', '', $trimmedResponse);
            $trimmedResponse = preg_replace('/\s*```$/', '', $trimmedResponse);
            $trimmedResponse = trim($trimmedResponse);

            $aiResult = json_decode($trimmedResponse, true);

            if (!$aiResult || !isset($aiResult['reply']) || !isset($aiResult['intent'])) {
                Log::error('AI returned invalid JSON', [
                    'raw_response'     => substr($response, 0, 500),
                    'trimmed_response' => substr($trimmedResponse, 0, 500),
                ]);
                return self::getFallbackResponse();
            }

            // Validate the reply TEXT (not the JSON wrapper)
            $validation = self::validateResponse($aiResult['reply'], $aiResult['intent']);

            if (!$validation['valid']) {
                Log::warning('AI response validation failed', [
                    'reasons' => $validation['reasons'],
                    'intent'  => $aiResult['intent'],
                    'reply'   => substr($aiResult['reply'], 0, 200),
                ]);
                return self::getFallbackResponse();
            }

            return [
                'success'          => true,
                'reply'            => $aiResult['reply'],
                'intent'           => $aiResult['intent'],
                'needs_escalation' => $aiResult['needs_escalation'] ?? false,
                'confidence'       => $aiResult['confidence'] ?? 0.7,
                'validation'       => $validation,
            ];

        } catch (\Exception $e) {
            Log::error('AI call failed', [
                'message' => $message,
                'error'   => $e->getMessage(),
            ]);
            return self::getFallbackResponse();
        }
    }

    /**
     * Fallback Response
     */
    private static function getFallbackResponse(): array
    {
        return [
            'success' => false,
            'reply' => "I apologize, but I'm having trouble processing your request right now. Let me connect you with a human agent who can help you better.",
            'intent' => 'escalation',
            'needs_escalation' => true,
            'confidence' => 0.5,
            'validation' => ['valid' => false, 'reasons' => ['ai_failure']]
        ];
    }

    /**
     * AI Chat with Retry Logic
     */
    private static function callAIChatWithRetry(array $messages, int $maxRetries = 3, int $baseDelay = 1000): string
    {
        $lastError = null;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                return self::callAIChat($messages);
            } catch (\Exception $e) {
                $lastError = $e;

                if (str_contains($e->getMessage(), '429') || str_contains($e->getMessage(), '500')) {
                    if ($attempt < $maxRetries) {
                        $delay = $baseDelay * pow(2, $attempt - 1);
                        usleep($delay * 1000);
                        continue;
                    }
                }

                if ($attempt < $maxRetries) {
                    usleep($baseDelay * 1000);
                }
            }
        }

        throw $lastError;
    }

    /**
     * Call AI Chat
     */
    private static function callAIChat(array $messages): string
    {
        $provider = self::getAIProvider();
        $apiKey = self::getAIAPIKey();

        if ($provider === 'gemini') {
            return self::callGeminiChat($messages);
        }

        return self::callGeminiChat($messages);
    }

    /**
     * Call Gemini API
     *
     * Gemini does NOT support a "system" role in the `contents` array.
     * System-level instructions must go in `systemInstruction` (a separate
     * top-level key).  Sending two consecutive "user" turns (system→user,
     * user→user) confuses the model and often causes API errors or empty
     * responses that trigger our fallback message.
     */
    private static function callGeminiChat(array $messages): string
    {
        $apiKey = self::getAIAPIKey();
        $model  = self::getAIModel();

        // Separate system prompt from chat turns
        $systemText = '';
        $contents   = [];

        foreach ($messages as $msg) {
            if ($msg['role'] === 'system') {
                $systemText .= $msg['content'] . "\n";
            } else {
                $contents[] = [
                    'role'  => $msg['role'], // 'user' or 'model'
                    'parts' => [['text' => $msg['content']]],
                ];
            }
        }

        $payload = [
            'contents'         => $contents,
            'generationConfig' => [
                'temperature'     => 0.7,
                'maxOutputTokens' => 500,
            ],
        ];

        // Send system prompt via the dedicated key Gemini supports
        if ($systemText !== '') {
            $payload['systemInstruction'] = [
                'parts' => [['text' => trim($systemText)]],
            ];
        }

        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
        ])->post(
            "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}",
            $payload
        );

        if (!$response->successful()) {
            throw new \Exception('Gemini API error: ' . $response->body());
        }

        $data = $response->json();
        return $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
    }

    /**
     * Priority Escalation Classification
     */
    public static function classifyEscalationPriority(array $escalationData): array
    {
        $priority = 'normal';
        $priorityScore = 1;
        $responseTime = '5_minutes';

        $userTier = $escalationData['user_tier'] ?? 'regular';
        $sentiment = $escalationData['sentiment'] ?? 'neutral';
        $reason = $escalationData['reason'] ?? 'standard';

        if ($userTier === 'vip') {
            $priority = 'high';
            $priorityScore = 3;
            $responseTime = '1_minute';
        }

        if ($sentiment === 'negative' || $sentiment === 'angry') {
            $priority = 'high';
            $priorityScore = 4;
            $responseTime = 'immediate';
        }

        if ($reason === 'system_failure') {
            $priority = 'high';
            $priorityScore = 3;
            $responseTime = 'immediate';
        }

        return [
            'priority' => $priority,
            'priority_score' => $priorityScore,
            'response_time_sla' => $responseTime,
            'notification_level' => $priorityScore
        ];
    }

    /**
     * Legacy methods for backward compatibility
     */
    public static function handleMessage(string $message, array $context = []): array
    {
        $aiResult = self::callAIWithJSON($message, $context);

        return [
            'intent' => $aiResult['intent'],
            'response' => $aiResult['reply'],
            'confidence' => $aiResult['confidence'] * 100,
            'issues' => $aiResult['validation']['reasons'],
            'intent_confidence' => $aiResult['confidence'],
            'evaluation_confidence' => $aiResult['confidence'],
            'context_score' => 0.8
        ];
    }

    public static function calculateConfidence(array $intent, array $evaluation, float $contextScore = 0): float
    {
        $intentConfidence = $intent['confidence'] ?? 0.5;
        $evaluationConfidence = $evaluation['confidence'] ?? 0.7;

        $confidence = ($intentConfidence * 0.5) + ($evaluationConfidence * 0.3) + ($contextScore * 0.2);

        return max(0, min(100, $confidence * 100));
    }

    public static function detectIntent(string $message): array
    {
        $messageLower = strtolower(trim($message));

        if (strlen($message) < 2) {
            return ['intent' => 'unknown', 'confidence' => 0.5];
        }

        $greetings = ['السلام', 'مرحبا', 'أهلا', 'hi', 'hello', 'hey', 'هاي', 'good morning', 'good evening', 'صباح', 'مساء'];
        foreach ($greetings as $greeting) {
            if (str_contains($messageLower, $greeting)) {
                return ['intent' => 'greeting', 'confidence' => 0.9];
            }
        }

        $orderKeywords = ['طلب', 'طلب', 'order', 'فين', 'حالة', 'سعر', 'price'];
        foreach ($orderKeywords as $keyword) {
            if (str_contains($messageLower, $keyword)) {
                return ['intent' => 'order', 'confidence' => 0.8];
            }
        }

        return ['intent' => 'unknown', 'confidence' => 0.5];
    }

    public static function applyTonePersona(string $systemPrompt, array $toneStyle, string $language): string
    {
        return $systemPrompt;
    }

    public static function checkBusinessHours(?BusinessProfile $business): array
    {
        if (!$business || !$business->business_hours_enabled) {
            return [
                'should_use_hours_routing' => false,
                'is_after_hours' => false,
                'routing_message' => null
            ];
        }

        $currentTime = now();
        $currentDay = strtolower($currentTime->format('l'));
        $currentTimeHours = $currentTime->format('H:i');

        $workingDays = is_array($business->working_days) ? $business->working_days : [];
        $workingFrom = $business->working_from ?? '09:00';
        $workingTo = $business->working_to ?? '17:00';

        $isWorkingDay = in_array($currentDay, array_map('strtolower', $workingDays));
        $isWorkingHours = $currentTimeHours >= $workingFrom && $currentTimeHours <= $workingTo;

        $isAfterHours = !$isWorkingDay || !$isWorkingHours;

        if ($isAfterHours) {
            $routingMessage = $business->after_hours_message ??
                ($currentDay === 'friday' || $currentDay === 'saturday'
                    ? "نحن مغلقون حالياً. سنعود إليك في اليوم التالي."
                    : "We're currently closed. We'll follow up with you during business hours.");
        } else {
            $routingMessage = null;
        }

        return [
            'should_use_hours_routing' => $isAfterHours,
            'is_after_hours' => $isAfterHours,
            'routing_message' => $routingMessage
        ];
    }

    public static function detectHandoff(string $message, array $conversationHistory): array
    {
        $hardEscalation = self::checkHardEscalation($message);

        if ($hardEscalation['force_escalation']) {
            return [
                'should_escalate' => true,
                'confidence' => 100,
                'reasons' => [$hardEscalation['reason']]
            ];
        }

        return [
            'should_escalate' => false,
            'confidence' => 30,
            'reasons' => []
        ];
    }
}
