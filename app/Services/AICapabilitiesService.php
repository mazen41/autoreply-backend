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
        return env('GEMINI_MODEL', 'gemini-2.5-flash');
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
     * Main handler - complete AI pipeline (optimized to reduce API calls)
     */
    public static function handleMessage(string $message, array $context = []): array
    {
        // Step 1: Generate response (AI call #1)
        $response = self::generateResponse($message, $context);

        // Step 2: Combine intent detection + evaluation in one AI call (AI call #2)
        $combinedAnalysis = self::analyzeMessageAndResponse($message, $response);

        // Step 3: Calculate context score
        $contextScore = self::calculateContextScore($context);

        // Step 4: Calculate final confidence
        $confidence = self::calculateConfidence(
            ['intent' => $combinedAnalysis['intent'], 'confidence' => $combinedAnalysis['intent_confidence']],
            ['confidence' => $combinedAnalysis['response_quality'], 'issues' => $combinedAnalysis['issues']],
            $contextScore
        );

        return [
            'intent' => $combinedAnalysis['intent'],
            'response' => $response,
            'confidence' => $confidence,
            'issues' => $combinedAnalysis['issues'],
            'intent_confidence' => $combinedAnalysis['intent_confidence'],
            'evaluation_confidence' => $combinedAnalysis['response_quality'],
            'context_score' => $contextScore
        ];
    }

    /**
     * Combined analysis - intent + evaluation in one AI call to reduce API usage
     */
    private static function analyzeMessageAndResponse(string $message, string $response): array
    {
        try {
            $prompt = "Analyze this conversation in JSON format:

User message: \"{$message}\"
AI response: \"{$response}\"

Provide:
1. Intent classification (greeting, question, order, support, complaint, spam, unknown)
2. Intent confidence (0-1)
3. Response quality score (0-1) based on correctness, clarity, usefulness, completeness
4. Any issues with the response (optional list)

Return JSON:
{
  \"intent\": \"string\",
  \"intent_confidence\": float,
  \"response_quality\": float,
  \"issues\": [\"optional list of problems\"]
}";

            $aiResponse = self::callAI($prompt);
            $result = json_decode($aiResponse, true);

            if ($result && isset($result['intent']) && isset($result['intent_confidence']) && isset($result['response_quality'])) {
                return [
                    'intent' => $result['intent'],
                    'intent_confidence' => (float)$result['intent_confidence'],
                    'response_quality' => (float)$result['response_quality'],
                    'issues' => $result['issues'] ?? []
                ];
            }

            // Fallback if AI fails
            return [
                'intent' => 'unknown',
                'intent_confidence' => 0.5,
                'response_quality' => 0.7,
                'issues' => ['ai_analysis_failed']
            ];

        } catch (\Exception $e) {
            Log::error('AI combined analysis failed', [
                'message' => $message,
                'error' => $e->getMessage()
            ]);
            return [
                'intent' => 'unknown',
                'intent_confidence' => 0.5,
                'response_quality' => 0.6,
                'issues' => ['analysis_failed']
            ];
        }
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
        $model = self::getAIModel();

        $maxRetries = 3;
        $retryDelay = 1000;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
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
                    'contents' => $contents
                ]);

                if (!$response->successful()) {
                    if ($response->status() === 429) {
                        Log::warning('Gemini rate limit hit on chat, retrying', [
                            'attempt' => $attempt,
                            'delay' => $retryDelay
                        ]);
                        
                        if ($attempt < $maxRetries) {
                            usleep($retryDelay * 1000);
                            $retryDelay *= 2;
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
                
                Log::warning('Gemini chat API call failed, retrying', [
                    'attempt' => $attempt,
                    'error' => $e->getMessage()
                ]);
                
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
