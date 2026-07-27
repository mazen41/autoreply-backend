<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BlogAIService
{
    /**
     * Call AI for blog-related tasks using the global admin settings
     * 
     * @param string $prompt The prompt for the AI
     * @param array $context Optional context messages
     * @return string|null AI response or null on failure
     */
    public static function callAI(string $prompt, array $context = [], ?string $systemPrompt = null): ?string
    {
        $primary = config('services.ai.provider', 'gemini');
        $fallback = config('services.ai.fallback_provider', $primary === 'gemini' ? 'claude' : 'gemini');
        $providers = array_values(array_unique(array_filter([$primary, $fallback])));

        foreach ($providers as $provider) {
            $response = match ($provider) {
                'claude' => self::callClaudeAPI($prompt, $context, $systemPrompt),
                'gemini' => self::callGeminiAPI($prompt, $context, $systemPrompt),
                default => null,
            };

            if ($response) {
                Log::info('BlogAIService: AI provider succeeded', ['provider' => $provider]);
                return $response;
            }

            Log::warning('BlogAIService: AI provider failed, checking fallback', ['provider' => $provider]);
        }

        return null;
    }

    /**
     * Call Claude API for blog tasks
     */
    private static function callClaudeAPI(string $prompt, array $context, ?string $systemPrompt = null): ?string
    {
        $apiKey = config('services.claude.api_key');
        if (!$apiKey) {
            Log::error('BlogAIService: ANTHROPIC_API_KEY not set');
            return null;
        }

        try {
            $messages = [];
            
            // Add context if provided
            foreach ($context as $ctx) {
                $messages[] = [
                    'role' => $ctx['role'] ?? 'user',
                    'content' => $ctx['content']
                ];
            }
            
            // Add the main prompt
            $messages[] = [
                'role' => 'user',
                'content' => $prompt
            ];

            $payload = [
                'model' => config('services.claude.model', 'claude-haiku-4-5-20251001'),
                'max_tokens' => (int) config('services.ai.max_tokens', 1000),
                'messages' => $messages,
            ];

            if ($systemPrompt) {
                $payload['system'] = $systemPrompt;
            }

            $response = Http::timeout((int) config('services.ai.timeout', 30))
                ->withHeaders([
                    'x-api-key' => $apiKey,
                    'anthropic-version' => '2023-06-01',
                    'Content-Type' => 'application/json',
                ])
                ->post('https://api.anthropic.com/v1/messages', $payload);

            if (!$response->successful()) {
                Log::error('BlogAIService: Claude API request failed', [
                    'status' => $response->status(),
                    'body' => $response->json(),
                ]);
                return null;
            }

            $data = $response->json();
            return $data['content'][0]['text'] ?? null;

        } catch (\Exception $e) {
            Log::error('BlogAIService: Claude API exception', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Call Gemini API for blog tasks
     */
    private static function callGeminiAPI(string $prompt, array $context, ?string $systemPrompt = null): ?string
    {
        $apiKey = config('services.gemini.api_key');
        if (!$apiKey) {
            Log::error('BlogAIService: GEMINI_API_KEY not set');
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
        $lastRole = null;
        
        foreach ($context as $msg) {
            $role = $msg['role'] === 'assistant' ? 'model' : 'user';
            
            // Merge consecutive messages of the same role
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
        
        // Add the main prompt
        $contents[] = [
            'role' => 'user',
            'parts' => [['text' => $prompt]]
        ];

        foreach ($models as $model) {
            try {
                $postData = [
                    'contents' => $contents,
                    'generationConfig' => [
                        'maxOutputTokens' => (int) config('services.ai.max_tokens', 1000),
                        'temperature' => (float) config('services.ai.temperature', 0.7),
                    ],
                ];

                if ($systemPrompt) {
                    $postData['systemInstruction'] = [
                        'parts' => [['text' => $systemPrompt]],
                    ];
                }

                $response = Http::timeout((int) config('services.ai.timeout', 30))
                    ->withHeaders([
                        'Content-Type' => 'application/json',
                    ])
                    ->post("https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}", $postData);

                if (!$response->successful()) {
                    Log::warning('BlogAIService: Gemini model failed', [
                        'model' => $model,
                        'status' => $response->status(),
                    ]);
                    continue;
                }

                $data = $response->json();
                return $data['candidates'][0]['content']['parts'][0]['text'] ?? null;

            } catch (\Exception $e) {
                Log::warning('BlogAIService: Gemini model exception', [
                    'model' => $model,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }
        }

        return null;
    }

    /**
     * Get current AI provider information
     */
    public static function getProviderInfo(): array
    {
        return [
            'primary' => config('services.ai.provider', 'gemini'),
            'fallback' => config('services.ai.fallback_provider', 'claude'),
            'gemini_configured' => (bool) config('services.gemini.api_key'),
            'claude_configured' => (bool) config('services.claude.api_key'),
            'gemini_model' => config('services.gemini.model', 'gemini-2.5-flash'),
            'claude_model' => config('services.claude.model', 'claude-haiku-4-5-20251001'),
        ];
    }
}