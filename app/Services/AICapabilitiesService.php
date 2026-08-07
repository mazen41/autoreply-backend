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
     */
    private static function getUltimateSystemPrompt(array $context = []): string
    {
        $businessName = $context['business_name'] ?? 'our business';
        $platform = $context['platform'] ?? 'whatsapp';
        $language = $context['language'] ?? 'english';
        $hasKnowledgeBase = !empty($context['knowledge_base']);
        $hasOrderData = !empty($context['order_data']);

        $prompt = "You are an AI Customer Support Assistant for a business.

You ONLY generate replies.
You DO NOT control workflows, APIs, logic, or databases.

The system handles:
- Platform detection (WhatsApp, Instagram, Facebook)
- Customer lookup (Salla)
- Order retrieval
- Knowledge base retrieval
- Escalation routing

You ONLY respond using provided data.

--------------------------------------------------
🔴 CORE RULES (STRICT)
--------------------------------------------------

1. You MUST always reply.
2. You MUST ONLY use:
   - Provided knowledge base
   - Provided documents
   - Provided order data
3. You MUST NEVER:
   - Guess
   - Invent information
   - Assume missing data
4. If info is missing → fallback + escalation

--------------------------------------------------
🟢 GREETING
--------------------------------------------------

If user says greeting (hi, hello, hey, السلام عليكم):

Reply:
\"Hi 👋 Welcome to {$businessName}! How can I help you today?\"

Intent = greeting

--------------------------------------------------
🟢 GENERAL QUESTIONS
--------------------------------------------------

";

        if ($hasKnowledgeBase) {
            $prompt .= "You have access to this knowledge base:\n" . $context['knowledge_base'] . "\n\n";
            $prompt .= "IF answer exists in knowledge:\n→ Answer clearly\n\n";
        }

        $prompt .= "IF NOT:\n→ Reply EXACTLY:\n\n\"I'm really sorry 😔 I couldn't find the exact information for your request, but no worries — I'll forward this to our team and they'll get back to you shortly.\"\n\n→ needs_escalation = true\n→ intent = escalation\n\n";

        $prompt .= "--------------------------------------------------
🟢 ORDER SUPPORT (SALLA)
--------------------------------------------------

You will RECEIVE order data. NEVER fetch anything.

";

        if ($hasOrderData) {
            $prompt .= "ORDER DATA PROVIDED:\n" . json_encode($context['order_data'], JSON_PRETTY_PRINT) . "\n\n";
            $prompt .= "Reply:\n\n\"Here are your order details 📦\n\n• Order Number: {$context['order_data']['order_number']}\n• Status: {$context['order_data']['status']}\n• Shipping Status: {$context['order_data']['shipping_status']}\n• Estimated Delivery: {$context['order_data']['delivery_date']}\"\n\nIntent = order\n\n";
        } else {
            $prompt .= "-------------------------\nIF ORDER DATA MISSING:\n-------------------------\n\n";
            if ($platform === 'whatsapp') {
                $prompt .= "Reply: \"Could you please send your order number so I can check your order? 😊\"\n\n";
            } else {
                $prompt .= "Reply: \"Please send your phone number or order number so I can check your order 😊\"\n\n";
            }
            $prompt .= "Intent = order\n\n";
        }

        $prompt .= "--------------------------------------------------
🔴 ESCALATION RULES
--------------------------------------------------

Trigger escalation if:

- User asks for human/agent/support
- User is angry or frustrated
- You cannot answer

Reply:

\"Sure 👍 I'm connecting you with a human agent now. Please wait a moment.\"

Intent = escalation
needs_escalation = true

--------------------------------------------------
🟡 STYLE
--------------------------------------------------

- Friendly
- Short
- Clear
- Light emojis

";

        if ($language === 'arabic') {
            $prompt .= "--------------------------------------------------
🔵 LANGUAGE REQUIREMENT
--------------------------------------------------

You MUST respond in Arabic.
Use appropriate Arabic greetings and cultural context.\n\n";
        } else {
            $prompt .= "--------------------------------------------------
🔵 LANGUAGE REQUIREMENT
--------------------------------------------------

You MUST respond in English.
Use appropriate English greetings and cultural context.\n\n";
        }

        $prompt .= "--------------------------------------------------
⚫ FINAL RULE
--------------------------------------------------

You MUST ALWAYS:
1. Answer
2. Ask for info
3. Or escalate

NEVER stay silent. NEVER guess.

--------------------------------------------------
🔴 OUTPUT FORMAT (STRICT)
--------------------------------------------------

Return ONLY valid JSON:

{
  \"reply\": \"your message to the user\",
  \"intent\": \"greeting | question | order | escalation\",
  \"needs_escalation\": true/false,
  \"confidence\": 0.0-1.0
}

Rules:
- No extra text
- No explanation
- No markdown
- Only JSON";

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
     */
    public static function validateResponse(string $response, string $intent): array
    {
        $validation = [
            'valid' => true,
            'reasons' => [],
            'warnings' => []
        ];

        if (empty(trim($response))) {
            $validation['valid'] = false;
            $validation['reasons'][] = 'empty_response';
        }

        if (strlen($response) > 500) {
            $validation['valid'] = false;
            $validation['reasons'][] = 'too_long';
            $validation['warnings'][] = "Response length: " . strlen($response) . " chars";
        }

        $uncertainPhrases = ['i think', 'maybe', 'possibly', 'might be', 'ربما', 'قد يكون', 'أعتقد'];
        $lowerResponse = strtolower($response);
        foreach ($uncertainPhrases as $phrase) {
            if (str_contains($lowerResponse, $phrase)) {
                $validation['valid'] = false;
                $validation['reasons'][] = 'uncertain_language';
                break;
            }
        }

        $systemKeywords = ['system prompt', 'instructions', 'rules', 'ai assistant', 'as an ai'];
        foreach ($systemKeywords as $keyword) {
            if (str_contains($lowerResponse, $keyword)) {
                $validation['valid'] = false;
                $validation['reasons'][] = 'system_instruction_leak';
                break;
            }
        }

        if (str_contains($response, '{') || str_contains($response, '}') || str_contains($response, '```')) {
            $validation['valid'] = false;
            $validation['reasons'][] = 'contains_code_or_json';
        }

        if ($intent === 'greeting' && strlen($response) > 100) {
            $validation['valid'] = false;
            $validation['reasons'][] = 'greeting_too_long';
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
                ['role' => 'user', 'content' => $message]
            ];

            $response = self::callAIChatWithRetry($messages);
            $trimmedResponse = trim($response);

            $aiResult = json_decode($trimmedResponse, true);

            if (!$aiResult || !isset($aiResult['reply']) || !isset($aiResult['intent'])) {
                Log::error('AI returned invalid JSON', ['response' => $trimmedResponse]);
                return self::getFallbackResponse();
            }

            $validation = self::validateResponse($aiResult['reply'], $aiResult['intent']);

            if (!$validation['valid']) {
                Log::warning('AI response validation failed', [
                    'reasons' => $validation['reasons'],
                    'intent' => $aiResult['intent']
                ]);
                return self::getFallbackResponse();
            }

            return [
                'success' => true,
                'reply' => $aiResult['reply'],
                'intent' => $aiResult['intent'],
                'needs_escalation' => $aiResult['needs_escalation'] ?? false,
                'confidence' => $aiResult['confidence'] ?? 0.7,
                'validation' => $validation
            ];

        } catch (\Exception $e) {
            Log::error('AI call failed', [
                'message' => $message,
                'error' => $e->getMessage()
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
     */
    private static function callGeminiChat(array $messages): string
    {
        $apiKey = self::getAIAPIKey();
        $model = self::getAIModel();

        $contents = [];
        foreach ($messages as $message) {
            $contents[] = [
                'role' => $message['role'] === 'system' ? 'user' : $message['role'],
                'parts' => [
                    ['text' => $message['content']]
                ]
            ];
        }

        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
        ])->post("https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}", [
            'contents' => $contents,
            'generationConfig' => [
                'temperature' => 0.7,
                'maxOutputTokens' => 500
            ]
        ]);

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
