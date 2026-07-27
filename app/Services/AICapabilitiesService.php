<?php

namespace App\Services;

use App\Models\BusinessProfile;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AICapabilitiesService
{
    /**
     * Get confidence score for AI response based on knowledge base coverage
     * 
     * @param string $userMessage The customer's message
     * @param string $aiResponse The AI's generated response
     * @param BusinessProfile $business The business profile with knowledge base
     * @return int Confidence score 0-100
     */
    public static function calculateConfidence(string $userMessage, string $aiResponse, BusinessProfile $business): int
    {
        $confidence = 50; // Base confidence

        // Check if AI response contains uncertain phrases
        $uncertainPhrases = [
            "I don't know", "I'm not sure", "I don't have that information",
            "لا أعرف", "لست متأكدا", "ليس لدي هذه المعلومات",
            "might", "could be", "possibly", "ربما", "قد يكون"
        ];

        foreach ($uncertainPhrases as $phrase) {
            if (stripos($aiResponse, $phrase) !== false) {
                $confidence -= 30;
                break;
            }
        }

        // Check if response is specific and actionable
        $specificIndicators = [
            'specific details', 'exact', 'precisely', 'دقيقة', 'تحديداً',
            '€', '$', 'SAR', 'ريال', 'AM', 'PM', 'صباحاً', 'مساءً'
        ];

        foreach ($specificIndicators as $indicator) {
            if (stripos($aiResponse, $indicator) !== false) {
                $confidence += 15;
            }
        }

        // Check if response uses knowledge base content
        $knowledgeContent = '';
        foreach ($business->knowledgeFiles()->get() as $file) {
            $knowledgeContent .= ' ' . strtolower($file->extracted_text);
        }

        $userWords = array_filter(explode(' ', strtolower($userMessage)));
        $responseWords = array_filter(explode(' ', strtolower($aiResponse)));

        $matchingWords = 0;
        foreach ($responseWords as $word) {
            if (strlen($word) > 3 && str_contains($knowledgeContent, $word)) {
                $matchingWords++;
            }
        }

        if (count($responseWords) > 0) {
            $matchRatio = $matchingWords / count($responseWords);
            $confidence += ($matchRatio * 20);
        }

        // Check response length - too short suggests vagueness
        if (strlen($aiResponse) < 50) {
            $confidence -= 20;
        }

        // Check if response contains placeholders or filler
        $fillerPhrases = [
            "I'll help you with that", "Let me help you", "I'm here to assist",
            "سأساعدك بذلك", "دعني أساعدك", "أنا هنا للمساعدة"
        ];

        foreach ($fillerPhrases as $phrase) {
            if (stripos($aiResponse, $phrase) !== false) {
                $confidence -= 25;
                break;
            }
        }

        // Normalize to 0-100 range
        return max(0, min(100, (int)$confidence));
    }

    /**
     * Detect if conversation should be escalated to human
     * 
     * @param string $message The user's message
     * @param array $conversationHistory Recent messages in conversation
     * @return array [should_escalate, reason, confidence]
     */
    public static function detectHandoff(string $message, array $conversationHistory): array
    {
        $messageLower = strtolower($message);
        $escalationScore = 0;
        $reasons = [];

        // Frustration indicators
        $frustrationKeywords = [
            'angry', 'frustrated', 'upset', 'disappointed', 'terrible',
            'غاضب', 'محبط', ' disappointed', 'سيء', 'فظيع'
        ];

        foreach ($frustrationKeywords as $keyword) {
            if (str_contains($messageLower, $keyword)) {
                $escalationScore += 30;
                $reasons[] = "frustration_detected: {$keyword}";
            }
        }

        // Request for human
        $humanRequestKeywords = [
            'human', 'person', 'agent', 'representative', 'talk to someone',
            'إنسان', 'شخص', 'موظف', 'أريد التحدث مع شخص'
        ];

        foreach ($humanRequestKeywords as $keyword) {
            if (str_contains($messageLower, $keyword)) {
                $escalationScore += 40;
                $reasons[] = "human_requested: {$keyword}";
            }
        }

        // Complex indicators
        $complexityKeywords = [
            'complicated', 'complex', 'confusing', 'not clear',
            'complicated situation', 'special case', 'exception',
            'معقد', 'معقد', 'غامض', 'غير واضح', 'حالة خاصة'
        ];

        foreach ($complexityKeywords as $keyword) {
            if (str_contains($messageLower, $keyword)) {
                $escalationScore += 25;
                $reasons[] = "complexity_detected: {$keyword}";
            }
        }

        // Urgency indicators
        $urgencyKeywords = [
            'urgent', 'emergency', 'asap', 'immediately', 'right now',
            'طوارئ', 'عاجل', 'فوراً', 'حالاً', 'بسرعة'
        ];

        foreach ($urgencyKeywords as $keyword) {
            if (str_contains($messageLower, $keyword)) {
                $escalationScore += 20;
                $reasons[] = "urgency_detected: {$keyword}";
            }
        }

        // Check conversation context for repeated failed AI responses
        $recentAIFailures = 0;
        foreach (array_slice($conversationHistory, -5) as $msg) {
            if (isset($msg['is_ai']) && $msg['is_ai'] && 
                isset($msg['send_status']) && $msg['send_status'] === 'failed') {
                $recentAIFailures++;
            }
        }

        if ($recentAIFailures >= 2) {
            $escalationScore += 35;
            $reasons[] = "repeated_ai_failures: {$recentAIFailures}";
        }

        // Check for customer complaints
        $complaintKeywords = [
            'complaint', 'issue', 'problem', 'wrong', 'error', 'mistake',
            'شكوى', 'مشكلة', 'خطأ', 'غير صحيح', 'عطل'
        ];

        foreach ($complaintKeywords as $keyword) {
            if (str_contains($messageLower, $keyword)) {
                $escalationScore += 15;
                $reasons[] = "complaint_detected: {$keyword}";
            }
        }

        // Check message length - very long messages often indicate complex issues
        if (strlen($message) > 500) {
            $escalationScore += 10;
            $reasons[] = "long_message_complexity";
        }

        $shouldEscalate = $escalationScore >= 50;

        return [
            'should_escalate' => $shouldEscalate,
            'confidence' => min(100, $escalationScore),
            'reasons' => $reasons,
        ];
    }

    /**
     * Get AI response with structured tone/persona
     * 
     * @param string $systemPrompt Base system prompt
     * @param string $toneStyle Configured tone style
     * @param string $language Customer's language
     * @return string Modified system prompt with tone instructions
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
     * Check if business hours routing should be used
     * 
     * @param BusinessProfile $business Business profile with hours settings
     * @return array [should_use_hours_routing, is_after_hours, routing_message]
     */
    public static function checkBusinessHours(BusinessProfile $business): array
    {
        if (!$business->business_hours_enabled) {
            return [
                'should_use_hours_routing' => false,
                'is_after_hours' => false,
                'routing_message' => null
            ];
        }

        $currentTime = now();
        $currentDay = strtolower($currentTime->format('l')); // Monday, Tuesday, etc.
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
     * Extract intent/topic from conversation for auto-tagging
     * 
     * @param string $message The customer's message
     * @param BusinessProfile $business Business profile for context
     * @return array [tag, intent, confidence]
     */
    public static function extractIntent(string $message, BusinessProfile $business): array
    {
        $messageLower = strtolower($message);
        $intents = [
            'pricing' => ['price', 'cost', 'how much', 'سعر', 'كم تكلفة', 'ثمن'],
            'delivery' => ['delivery', 'shipping', 'when will it arrive', 'توصيل', 'شحن', 'متى يصل'],
            'hours' => ['hours', 'when are you open', 'working hours', 'ساعات', 'متى تفتحون', 'أوقات العمل'],
            'complaint' => ['complaint', 'issue', 'problem', 'wrong', 'شكوى', 'مشكلة', 'خطأ', 'غير صحيح'],
            'inquiry' => ['information', 'tell me about', 'what is', 'معلومات', 'أخبرني عن', 'ما هو'],
            'support' => ['help', 'assist', 'support', 'مساعدة', 'دعم', 'خدمة'],
            'booking' => ['book', 'reserve', 'appointment', 'حجز', 'احجز', 'موعد'],
            'payment' => ['payment', 'pay', 'credit card', 'دفع', 'دفع', 'بطاقة ائتمان'],
        ];

        $bestMatch = null;
        $highestScore = 0;

        foreach ($intents as $intent => $keywords) {
            $score = 0;
            foreach ($keywords as $keyword) {
                if (str_contains($messageLower, $keyword)) {
                    $score += 20;
                }
            }

            if ($score > $highestScore) {
                $highestScore = $score;
                $bestMatch = $intent;
            }
        }

        if ($bestMatch && $highestScore > 20) {
            return [
                'tag' => $bestMatch,
                'intent' => $bestMatch,
                'confidence' => min(100, $highestScore + 20) // Boost confidence
            ];
        }

        return [
            'tag' => 'general',
            'intent' => 'general',
            'confidence' => 30
        ];
    }
}