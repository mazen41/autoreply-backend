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
     * Detect intent using AI (semantic understanding)
     */
    public static function detectIntent(string $message): array
    {
        // Cache intent results for repeated messages
        $cacheKey = 'intent_' . md5($message);
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        // Skip AI for very short messages (< 2 chars) - these are usually unclear
        if (strlen(trim($message)) < 2) {
            return [
                'intent' => 'unknown',
                'confidence' => 0.5,
                'from_cache' => false
            ];
        }

        try {
            $prompt = "Classify this message into one of: greeting, question, order, support, complaint, spam, unknown

Message: \"{$message}\"

Return JSON:
{
  \"intent\": \"string\",
  \"confidence\": float (0-1)
}";

            $response = self::callAI($prompt);
            $result = json_decode($response, true);

            if ($result && isset($result['intent']) && isset($result['confidence'])) {
                $result['from_cache'] = false;
                // Cache for 1 hour
                Cache::put($cacheKey, $result, 3600);
                return $result;
            }

            // Fallback if AI fails
            return [
                'intent' => 'unknown',
                'confidence' => 0.5,
                'from_cache' => false
            ];

        } catch (\Exception $e) {
            Log::error('AI intent detection failed', [
                'message' => $message,
                'error' => $e->getMessage()
            ]);
            return [
                'intent' => 'unknown',
                'confidence' => 0.5,
                'from_cache' => false
            ];
        }
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
     * Evaluate response quality using AI
     */
    public static function evaluateResponse(string $message, string $response): array
    {
        try {
            $prompt = "Evaluate the quality of this AI response.

User message: \"{$message}\"
AI response: \"{$response}\"

Score based on:
- correctness (does it answer the question?)
- clarity (is it easy to understand?)
- usefulness (is it helpful?)
- completeness (does it provide needed information?)

Return JSON:
{
  \"confidence\": float (0-1),
  \"issues\": [\"optional list of problems\"]
}";

            $aiResponse = self::callAI($prompt);
            $result = json_decode($aiResponse, true);

            if ($result && isset($result['confidence'])) {
                return [
                    'confidence' => (float)$result['confidence'],
                    'issues' => $result['issues'] ?? []
                ];
            }

            // Fallback
            return [
                'confidence' => 0.7,
                'issues' => []
            ];

        } catch (\Exception $e) {
            Log::error('AI response evaluation failed', [
                'message' => $message,
                'response' => substr($response, 0, 100),
                'error' => $e->getMessage()
            ]);
            return [
                'confidence' => 0.6,
                'issues' => ['evaluation_failed']
            ];
        }
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
     * Main handler - complete AI pipeline
     */
    public static function handleMessage(string $message, array $context = []): array
    {
        // Step 1: Detect intent
        $intent = self::detectIntent($message);

        // Step 2: Generate response
        $response = self::generateResponse($message, $context);

        // Step 3: Evaluate response
        $evaluation = self::evaluateResponse($message, $response);

        // Step 4: Calculate context score
        $contextScore = self::calculateContextScore($context);

        // Step 5: Calculate final confidence
        $confidence = self::calculateConfidence($intent, $evaluation, $contextScore);

        return [
            'intent' => $intent['intent'],
            'response' => $response,
            'confidence' => $confidence,
            'issues' => $evaluation['issues'],
            'intent_confidence' => $intent['confidence'],
            'evaluation_confidence' => $evaluation['confidence'],
            'context_score' => $contextScore
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
     * Call Gemini API with prompt
     */
    private static function callGemini(string $prompt): string
    {
        $apiKey = self::getAIAPIKey();
        $model = self::getAIModel();

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
            throw new \Exception('Gemini API error: ' . $response->body());
        }

        $data = $response->json();
        return $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
    }

    /**
     * Call Gemini API with chat messages
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
            'contents' => $contents
        ]);

        if (!$response->successful()) {
            throw new \Exception('Gemini API error: ' . $response->body());
        }

        $data = $response->json();
        return $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
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
