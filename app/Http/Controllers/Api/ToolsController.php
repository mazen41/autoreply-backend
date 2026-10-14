<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ToolsController extends Controller
{
    public function aiCall(Request $request)
    {
        $validated = $request->validate([
            'system_prompt' => 'required|string',
            'user_message' => 'required|string',
        ]);

        $systemPrompt = $validated['system_prompt'];
        $userMessage = $validated['user_message'];

        // Use the same AI provider logic as the main system
        $primary = config('services.ai.provider', 'gemini');
        $fallback = config('services.ai.fallback_provider', $primary === 'gemini' ? 'claude' : 'gemini');
        $providers = array_values(array_unique(array_filter([$primary, $fallback])));

        foreach ($providers as $provider) {
            $result = match ($provider) {
                'claude' => $this->callClaudeAPI($systemPrompt, $userMessage),
                'gemini' => $this->callGeminiAPI($systemPrompt, $userMessage),
                'groq' => $this->callGroqAPI($systemPrompt, $userMessage),
                default => null,
            };

            if ($result) {
                Log::info('ToolsController: AI provider succeeded', ['provider' => $provider]);
                return response()->json(['result' => $result]);
            }

            Log::warning('ToolsController: AI provider failed, checking fallback', ['provider' => $provider]);
        }

        return response()->json(['error' => 'All AI providers failed'], 500);
    }

    private function callClaudeAPI(string $systemPrompt, string $userMessage): ?string
    {
        $apiKey = config('services.claude.api_key');
        if (!$apiKey) {
            Log::error('ToolsController: ANTHROPIC_API_KEY not set');
            return null;
        }

        try {
            $response = Http::timeout((int) config('services.ai.timeout', 30))
                ->withHeaders([
                    'x-api-key' => $apiKey,
                    'anthropic-version' => '2023-06-01',
                    'Content-Type' => 'application/json',
                ])
                ->post('https://api.anthropic.com/v1/messages', [
                    'model' => config('services.claude.model', 'claude-haiku-4-5-20251001'),
                    'max_tokens' => (int) config('services.ai.max_tokens', 2000),
                    'system' => $systemPrompt,
                    'messages' => [
                        ['role' => 'user', 'content' => $userMessage]
                    ],
                ]);

            if (!$response->successful()) {
                Log::error('ToolsController: Claude API request failed', [
                    'status' => $response->status(),
                ]);
                return null;
            }

            $data = $response->json();
            return $data['content'][0]['text'] ?? null;

        } catch (\Exception $e) {
            Log::error('ToolsController: Claude API exception', ['exception_type' => get_class($e)]);
            return null;
        }
    }

    private function callGeminiAPI(string $systemPrompt, string $userMessage): ?string
    {
        $apiKey = config('services.gemini.api_key');
        if (!$apiKey) {
            Log::error('ToolsController: GEMINI_API_KEY not set');
            return null;
        }

        $models = array_values(array_unique(array_filter([
            config('services.gemini.model', 'gemini-2.5-flash'),
            'gemini-2.5-flash',
            'gemini-2.0-flash-exp',
            'gemini-1.5-flash',
            'gemini-1.5-pro',
        ])));

        foreach ($models as $model) {
            try {
                $postData = [
                    'contents' => [
                        [
                            'role' => 'user',
                            'parts' => [['text' => $userMessage]]
                        ]
                    ],
                    'generationConfig' => [
                        'maxOutputTokens' => (int) config('services.ai.max_tokens', 2000),
                        'temperature' => (float) config('services.ai.temperature', 0.7),
                    ],
                ];

                if (!empty($systemPrompt)) {
                    $postData['systemInstruction'] = [
                        'parts' => [['text' => $systemPrompt]]
                    ];
                }

                $response = Http::timeout((int) config('services.ai.timeout', 30))
                    ->withHeaders([
                        'Content-Type' => 'application/json',
                    ])
                    ->post("https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}", $postData);

                if (!$response->successful()) {
                    Log::warning('ToolsController: Gemini model failed', [
                        'model' => $model,
                        'status' => $response->status(),
                    ]);
                    continue;
                }

                $data = $response->json();
                return $data['candidates'][0]['content']['parts'][0]['text'] ?? null;

            } catch (\Exception $e) {
                Log::warning('ToolsController: Gemini model exception', [
                    'model' => $model,
                    'exception_type' => get_class($e),
                ]);
                continue;
            }
        }

        return null;
    }

    private function callGroqAPI(string $systemPrompt, string $userMessage): ?string
    {
        $apiKey = config('services.groq.api_key');
        if (!$apiKey) {
            Log::error('ToolsController: GROQ_API_KEY not set');
            return null;
        }

        try {
            $response = Http::timeout((int) config('services.ai.timeout', 30))
                ->withToken($apiKey)
                ->post('https://api.groq.com/openai/v1/chat/completions', [
                    'model' => config('services.groq.model', 'openai/gpt-oss-120b'),
                    'max_tokens' => (int) config('services.ai.max_tokens', 2000),
                    'temperature' => (float) config('services.ai.temperature', 0.7),
                    'messages' => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user', 'content' => $userMessage],
                    ],
                ]);

            if (!$response->successful()) {
                Log::error('ToolsController: Groq API request failed', ['status' => $response->status()]);
                return null;
            }

            return $response->json('choices.0.message.content');
        } catch (\Throwable $exception) {
            Log::error('ToolsController: Groq API exception', ['exception_type' => get_class($exception)]);
            return null;
        }
    }
}
