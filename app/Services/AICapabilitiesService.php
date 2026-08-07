<?php

namespace App\Services;

use App\Models\BusinessProfile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AICapabilitiesService
{
    private static function getAIProvider(): string
    {
        return env('AI_PROVIDER', 'gemini');
    }

    private static function getAIModel(): string
    {
        return env('GEMINI_MODEL', 'gemini-3.5-flash');
    }

    private static function getAIAPIKey(): string
    {
        return env('GEMINI_API_KEY', '');
    }

    /**
     * Detect intent using AI (semantic understanding) - DEPRECATED: Use analyzeMessageAndResponse instead
     */
    public static function detectIntent(string $message): array
    {
        // Fallback to simple detection for backward compatibility
        $cacheKey = 'intent_' . md5($message);
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        // Simple keyword-based fallback for rate limiting
        $messageLower = strtolower(trim($message));
        
        // Very short messages
        if (strlen($message) < 2) {
            return ['intent' => 'unknown', 'confidence' => 0.5, 'from_cache' => false];
        }

        // Simple greeting detection
        $greetings = ['السلام', 'مرحبا', 'أهلا', 'hi', 'hello', 'hey', 'هاي', 'good morning', 'good evening', 'صباح', 'مساء'];
        foreach ($greetings as $greeting) {
            if (str_contains($messageLower, $greeting)) {
                $result = ['intent' => 'greeting', 'confidence' => 0.9, 'from_cache' => false];
                Cache::put($cacheKey, $result, 3600);
                return $result;
            }
        }

        // Order-related keywords
        $orderKeywords = ['طلب', 'طلب', 'order', 'فين', 'حالة', 'سعر', 'price'];
        foreach ($orderKeywords as $keyword) {
            if (str_contains($messageLower, $keyword)) {
                $result = ['intent' => 'order', 'confidence' => 0.8, 'from_cache' => false];
                Cache::put($cacheKey, $result, 3600);
                return $result;
            }
        }

        $result = ['intent' => 'unknown', 'confidence' => 0.5, 'from_cache' => false];
        Cache::put($cacheKey, $result, 3600);
        return $result;
    }

    /**
     * Generate AI response
     */
    public static function generateResponse(string $message, array $context = []): string
    {
        try {
            $systemPrompt = self::buildSystemPrompt($context);

            $messages = [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $message]
            ];

            $response = self::callAIChat($messages);
            return trim($response);

        } catch (\Exception $e) {
            Log::error('AI response generation failed', [
                'message' => $message,
                'error' => $e->getMessage()
            ]);
            return "I apologize, but I'm having trouble generating a response right now. Please try again later.";
        }
    }

    /**
     * Evaluate response quality using AI - DEPRECATED: Use analyzeMessageAndResponse instead
     */
    public static function evaluateResponse(string $message, string $response): array
    {
        // Simple heuristic fallback for rate limiting
        if (strlen($response) < 20) {
            return ['confidence' => 0.4, 'issues' => ['too_short']];
        }

        if (str_contains(strtolower($response), 'don\'t know') || str_contains(strtolower($response), 'not sure')) {
            return ['confidence' => 0.5, 'issues' => ['uncertain']];
        }

        return ['confidence' => 0.7, 'issues' => []];
    }

    /**
     * Calculate final confidence using AI-driven formula
     */
    public static function calculateConfidence(array $intent, array $evaluation, float $contextScore = 0): float
    {
        $intentConfidence = $intent['confidence'] ?? 0.5;
        $evaluationConfidence = $evaluation['confidence'] ?? 0.7;

        // Weighted formula as specified
        $confidence = ($intentConfidence * 0.5) + ($evaluationConfidence * 0.3) + ($contextScore * 0.2);

        // Convert to 0-100 range
        return max(0, min(100, $confidence * 100));
    }

    /**
     * Calculate context score based on data usage
     */
    public static function calculateContextScore(array $context): float
    {
        $score = 0;

        // Check if response uses store data
        if (isset($context['has_store_data']) && $context['has_store_data']) {
            $score += 0.3;
        }

        // Check if response uses product info
        if (isset($context['has_product_info']) && $context['has_product_info']) {
            $score += 0.3;
        }

        // Check if response uses order data
        if (isset($context['has_order_data']) && $context['has_order_data']) {
            $score += 0.4;
        }

        return min(1.0, $score);
    }

    /**
     * Score an already-generated reply for confidence + intent.
     * Called AFTER the reply has been generated so we evaluate the actual response,
     * not a generic placeholder.
     *
     * Returns:
     *   confidence        float  0-100
     *   intent            string
     *   intent_confidence float  0-1
     *   issues            array
     */
    public static function scoreReply(
        string $userMessage,
        string $aiReply,
        array  $conversationHistory,
        array  $context = []
    ): array {
        // --- Fast heuristic pre-checks (no AI call needed) ---

        // Too short to be useful
        if (strlen(trim($aiReply)) < 15) {
            return self::scoreResult('unknown', 0.5, 20, ['reply_too_short']);
        }

        // Vague filler phrases that signal the AI didn't actually know the answer
        $fillerPhrases = [
            "i'm here to assist", "i am here to assist", "how can i help you today",
            "أنا هنا لمساعدتك", "كيف يمكنني مساعدتك",
            "i don't have that information",
            "i apologize, but i'm having trouble",
        ];
        foreach ($fillerPhrases as $filler) {
            if (str_contains(strtolower($aiReply), $filler)) {
                return self::scoreResult('unknown', 0.4, 35, ['vague_filler_detected']);
            }
        }

        // Explicit uncertainty without offering help
        if (preg_match("/i (don't|do not|dont) know/i", $aiReply) && strlen($aiReply) < 80) {
            return self::scoreResult('unknown', 0.4, 40, ['uncertain_no_followup']);
        }

        // Context score from data richness
        $contextScore = self::calculateContextScore($context);

        // --- Single AI call to score intent + quality together ---
        try {
            $historyText = implode("\n", array_map(
                fn($m) => ($m['direction'] ?? ($m['role'] === 'user' ? 'inbound' : 'outbound')) . ': ' . $m['content'],
                array_slice($conversationHistory, -6)
            ));

            $businessName = $context['business_name'] ?? 'this business';
            $language     = $context['language'] ?? 'auto';

            $prompt = <<<PROMPT
You are evaluating whether an AI customer service reply is high quality and should be sent automatically.

Business: {$businessName}
Customer language: {$language}

Recent conversation:
{$historyText}

Customer's latest message: "{$userMessage}"

AI's proposed reply: "{$aiReply}"

Score this reply and return ONLY valid JSON — no markdown, no explanation:
{
  "intent": "<one of: greeting, question, order, support, complaint, booking, pricing, spam, unknown>",
  "intent_confidence": <float 0-1>,
  "response_quality": <float 0-1>,
  "issues": ["<list only real problems, or empty array>"],
  "reasoning": "<one sentence>"
}

Scoring guide for response_quality:
- 0.9-1.0: directly answers the question using specific business info, concise, correct language
- 0.7-0.89: answers well but slightly generic or missing a small detail
- 0.5-0.69: partially answers, vague, or slightly off-topic
- 0.3-0.49: mostly filler, does not answer the actual question
- 0.0-0.29: wrong, confusing, or harmful reply

Issues to flag (only real ones): too_short, vague, off_topic, wrong_language, contradicts_history, missing_key_info, hallucinated_info
PROMPT;

            $raw    = self::callGemini($prompt);
            $clean  = preg_replace('/```json|```/', '', $raw);
            $result = json_decode(trim($clean), true);

            if (
                $result &&
                isset($result['intent'], $result['intent_confidence'], $result['response_quality'])
            ) {
                $quality  = (float) $result['response_quality'];
                $intentC  = (float) $result['intent_confidence'];

                // Weighted confidence: 55% reply quality, 25% intent clarity, 20% context richness
                $raw100 = ($quality * 0.55 + $intentC * 0.25 + $contextScore * 0.20) * 100;
                $confidence = (int) max(0, min(100, round($raw100)));

                Log::info('AICapabilitiesService: scoreReply result', [
                    'intent'      => $result['intent'],
                    'quality'     => $quality,
                    'intentConf'  => $intentC,
                    'contextScore'=> $contextScore,
                    'confidence'  => $confidence,
                    'issues'      => $result['issues'] ?? [],
                    'reasoning'   => $result['reasoning'] ?? '',
                ]);

                return self::scoreResult(
                    $result['intent'],
                    $intentC,
                    $confidence,
                    $result['issues'] ?? []
                );
            }
        } catch (\Exception $e) {
            Log::warning('AICapabilitiesService: scoreReply AI call failed, using heuristic', [
                'error' => $e->getMessage(),
            ]);
        }

        // Heuristic fallback — reply exists and passed the pre-checks, give a reasonable middle score
        $fallbackConf = (int) (50 + $contextScore * 20);
        return self::scoreResult('unknown', 0.5, $fallbackConf, ['scoring_ai_unavailable']);
    }

    /**
     * Helper to build a consistent score result array.
     */
    private static function scoreResult(
        string $intent,
        float  $intentConfidence,
        int    $confidence,
        array  $issues
    ): array {
        return [
            'intent'            => $intent,
            'intent_confidence' => $intentConfidence,
            'confidence'        => $confidence,
            'issues'            => $issues,
        ];
    }

    /**
     * Main handler - kept for backward compatibility only.
     * New code should call callConfiguredAI() for the reply, then scoreReply() for scoring.
     * @deprecated Use scoreReply() directly after generating the reply.
     */
    public static function handleMessage(string $message, array $context = []): array
    {
        $response = self::generateResponse($message, $context);
        $scored   = self::scoreReply($message, $response, [], $context);

        return [
            'intent'               => $scored['intent'],
            'response'             => $response,
            'confidence'           => $scored['confidence'],
            'issues'               => $scored['issues'],
            'intent_confidence'    => $scored['intent_confidence'],
            'evaluation_confidence'=> $scored['confidence'] / 100,
            'context_score'        => self::calculateContextScore($context),
        ];
    }

    /**
     * Detect if conversation should be escalated using AI
     */
    public static function detectHandoff(string $message, array $conversationHistory): array
    {
        try {
            $historyText = implode("\n", array_map(fn($msg) => 
                ($msg['direction'] ?? 'unknown') . ': ' . ($msg['content'] ?? ''),
                array_slice($conversationHistory, -5)
            ));

            $prompt = "Analyze if this conversation should be escalated to a human agent.

Latest message: \"{$message}\"

Recent conversation history:
{$historyText}

Consider:
- Customer frustration or anger
- Requests for human agent
- Complex or unresolved issues
- Urgency indicators
- Repeated failed AI responses

Return JSON:
{
  \"should_escalate\": boolean,
  \"confidence\": float (0-1),
  \"reasons\": [\"optional list of reasons\"]
}";

            $response = self::callAI($prompt);
            $result = json_decode($response, true);

            if ($result && isset($result['should_escalate'])) {
                return [
                    'should_escalate' => $result['should_escalate'],
                    'confidence' => ($result['confidence'] ?? 0.7) * 100,
                    'reasons' => $result['reasons'] ?? []
                ];
            }

            // Fallback
            return [
                'should_escalate' => false,
                'confidence' => 30,
                'reasons' => ['ai_evaluation_failed']
            ];

        } catch (\Exception $e) {
            Log::error('AI handoff detection failed', [
                'message' => $message,
                'error' => $e->getMessage()
            ]);
            return [
                'should_escalate' => false,
                'confidence' => 30,
                'reasons' => ['handoff_detection_failed']
            ];
        }
    }

    /**
     * Call AI with prompt (for non-chat requests)
     */
    private static function callAI(string $prompt): string
    {
        $provider = self::getAIProvider();
        $apiKey = self::getAIAPIKey();

        if ($provider === 'gemini') {
            return self::callGemini($prompt);
        }

        // Fallback for other providers
        return self::callGemini($prompt);
    }

    /**
     * Call AI with chat messages
     */
    private static function callAIChat(array $messages): string
    {
        $provider = self::getAIProvider();
        $apiKey = self::getAIAPIKey();

        if ($provider === 'gemini') {
            return self::callGeminiChat($messages);
        }

        // Fallback
        return self::callGeminiChat($messages);
    }

    /**
     * Call Gemini API with retry logic and rate limiting
     */
    private static function callGemini(string $prompt): string
    {
        $apiKey = self::getAIAPIKey();
        $model = self::getAIModel();

        $maxRetries = 3;
        $retryDelay = 1000; // Start with 1 second

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                $response = Http::withHeaders([
                    'Content-Type' => 'application/json',
                ])->post("https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}", [
                    'contents' => [
                        [
                            'parts' => [
                                ['text' => $prompt]
                            ]
                        ]
                    ]
                ]);

                if (!$response->successful()) {
                    // Check if it's a rate limit error
                    if ($response->status() === 429) {
                        Log::warning('Gemini rate limit hit, retrying', [
                            'attempt' => $attempt,
                            'delay' => $retryDelay
                        ]);
                        
                        if ($attempt < $maxRetries) {
                            usleep($retryDelay * 1000); // Convert to microseconds
                            $retryDelay *= 2; // Exponential backoff
                            continue;
                        }
                    }
                    
                    throw new \Exception('Gemini API error: ' . $response->body());
                }

                $data = $response->json();
                return $data['candidates'][0]['content']['parts'][0]['text'] ?? '';

            } catch (\Exception $e) {
                if ($attempt === $maxRetries) {
                    throw $e;
                }
                
                Log::warning('Gemini API call failed, retrying', [
                    'attempt' => $attempt,
                    'error' => $e->getMessage()
                ]);
                
                usleep($retryDelay * 1000);
                $retryDelay *= 2;
            }
        }

        throw new \Exception('Gemini API failed after retries');
    }

    /**
     * Call Gemini API with chat messages and retry logic
     */
    private static function callGeminiChat(array $messages): string
    {
        $apiKey = self::getAIAPIKey();
        $model  = self::getAIModel();

        $maxRetries = 3;
        $retryDelay = 1000;

        // Separate system message from conversation turns
        $systemText = null;
        $contents   = [];
        $lastRole   = null;

        foreach ($messages as $message) {
            if ($message['role'] === 'system') {
                $systemText = $message['content'];
                continue;
            }

            $role = $message['role']; // 'user' or 'assistant'
            $geminiRole = $role === 'assistant' ? 'model' : 'user';

            // Gemini requires strictly alternating roles — merge consecutive same-role messages
            if ($geminiRole === $lastRole && !empty($contents)) {
                $lastIndex = count($contents) - 1;
                $contents[$lastIndex]['parts'][0]['text'] .= "\n\n" . $message['content'];
            } else {
                $contents[] = [
                    'role'  => $geminiRole,
                    'parts' => [['text' => $message['content']]],
                ];
                $lastRole = $geminiRole;
            }
        }

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                $postData = ['contents' => $contents];

                // Use systemInstruction (correct Gemini field — NOT a user turn)
                if ($systemText) {
                    $postData['systemInstruction'] = [
                        'parts' => [['text' => $systemText]],
                    ];
                }

                $response = Http::withHeaders(['Content-Type' => 'application/json'])
                    ->post("https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}", $postData);

                if ($response->successful()) {
                    return $response->json('candidates')[0]['content']['parts'][0]['text'] ?? '';
                }

                if ($response->status() === 429 && $attempt < $maxRetries) {
                    usleep($retryDelay * 1000);
                    $retryDelay *= 2;
                    continue;
                }

                throw new \Exception('Gemini chat API error: ' . $response->body());

            } catch (\Exception $e) {
                if ($attempt === $maxRetries) throw $e;
                usleep($retryDelay * 1000);
                $retryDelay *= 2;
            }
        }

        throw new \Exception('Gemini chat API failed after retries');
    }

    /**
     * Build system prompt with context
     */
    private static function buildSystemPrompt(array $context = []): string
    {
        $basePrompt = "You are an AI customer support assistant for a business. Answer questions truthfully and helpfully. If you don't know the answer, politely state that you don't know and offer to connect them with a human agent.";

        // Add business context if available
        if (isset($context['business_name'])) {
            $basePrompt .= "\n\nBusiness: {$context['business_name']}";
        }

        // Add language context
        if (isset($context['language'])) {
            $basePrompt .= "\n\nLanguage: Respond in {$context['language']}";
        }

        // Add product/order context if available
        if (isset($context['order_context'])) {
            $basePrompt .= "\n\nOrder Context: {$context['order_context']}";
        }

        return $basePrompt;
    }

    /**
     * Get AI response with structured tone/persona (kept for backward compatibility)
     */
    public static function applyTonePersona(string $systemPrompt, array $toneStyle, string $language): string
    {
        $toneInstructions = "\n### TONE & PERSONA ###\n";

        $tone = $toneStyle['tone'] ?? 'friendly';
        $formality = $toneStyle['formality'] ?? 'casual';
        $focus = $toneStyle['focus'] ?? 'support';

        // Tone instructions
        $toneMap = [
            'friendly' => 'Be warm, approachable, and conversational. Use casual language and personal touches.',
            'professional' => 'Be formal, polite, and business-like. Use proper titles and professional salutations.',
            'enthusiastic' => 'Be energetic, positive, and engaging. Use exclamation points sparingly but effectively.',
            'empathetic' => 'Be understanding, caring, and supportive. Acknowledge customer feelings.',
        ];

        $toneInstructions .= ($toneMap[$tone] ?? $toneMap['friendly']) . "\n";

        // Formality instructions
        $formalityMap = [
            'formal' => 'Use formal language (Dear Mr./Ms., Sincerely, etc.). Avoid contractions.',
            'casual' => 'Use natural, conversational language. Contractions are fine.',
            'semi-formal' => 'Balance professional warmth with appropriate formality.',
        ];

        $toneInstructions .= ($formalityMap[$formality] ?? $formalityMap['casual']) . "\n";

        // Focus instructions
        $focusMap = [
            'support' => 'Focus on solving problems and providing helpful information.',
            'sales' => 'Focus on features, benefits, and guiding toward conversions while being helpful.',
            'information' => 'Focus on providing accurate information and being comprehensive.',
        ];

        $toneInstructions .= ($focusMap[$focus] ?? $focusMap['support']) . "\n";

        // Language-specific adjustments
        if ($language === 'arabic') {
            $toneInstructions .= "### ARABIC LANGUAGE SPECIFIC ###\n";
            $toneInstructions .= "- Use appropriate Arabic honorifics (حضرة، شكراً، etc.)\n";
            $toneInstructions .= "- Start with Islamic greeting when appropriate (السلام عليكم ورحمة الله وبركاته)\n";
            $toneInstructions .= "- Use polite forms (تفضل، من فضلك، إلخ)\n";
            $toneInstructions .= "- End with polite closing (مع تحياتي، شكراً لك)\n";
        } else {
            $toneInstructions .= "### ENGLISH LANGUAGE SPECIFIC ###\n";
            $toneInstructions .= "- Use appropriate greetings (Hello, Hi, Good morning)\n";
            $toneInstructions .= "- Be polite and professional (Please, Thank you, Appreciate)\n";
            $toneInstructions .= "- Use friendly closings (Best regards, Thank you, Have a great day)\n";
        }

        return $systemPrompt . $toneInstructions;
    }

    /**
     * Check if business hours routing should be used (kept as business logic)
     */
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

    /**
     * Legacy method for backward compatibility - uses new AI pipeline
     */
    public static function calculateConfidenceLegacy(string $userMessage, string $aiResponse, ?BusinessProfile $business): int
    {
        // Use the new AI-driven pipeline
        $result = self::handleMessage($userMessage, [
            'business' => $business
        ]);

        return (int)$result['confidence'];
    }

    /**
     * Legacy method for backward compatibility - uses new AI intent detection
     */
    public static function extractIntent(string $message, ?BusinessProfile $business): array
    {
        // Use the new AI-driven intent detection
        $intent = self::detectIntent($message);

        return [
            'tag' => $intent['intent'],
            'intent' => $intent['intent'],
            'confidence' => (int)($intent['confidence'] * 100)
        ];
    }
}
