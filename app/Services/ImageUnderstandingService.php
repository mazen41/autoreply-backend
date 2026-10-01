<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ImageUnderstandingService
{
    /**
     * Analyze an image using Vision API (GPT-4o or Gemini).
     *
     * @param string $imageUrl Public URL or base64-encoded image
     * @param string $prompt Custom analysis prompt
     * @return array Structured analysis result
     */
    public static function analyzeImage(string $imageUrl, string $prompt = 'Describe this image and identify any products or receipts'): array
    {
        $provider = env('VISION_PROVIDER', 'openai');

        return match ($provider) {
            'gemini' => self::analyzeWithGemini($imageUrl, $prompt),
            default => self::analyzeWithOpenAI($imageUrl, $prompt),
        };
    }

    /**
     * Analyze image using OpenAI GPT-4o Vision.
     */
    private static function analyzeWithOpenAI(string $imageUrl, string $prompt): array
    {
        $apiKey = env('OPENAI_API_KEY');

        if (!$apiKey) {
            return self::getEmptyResult('OpenAI API key not configured');
        }

        try {
            $imageData = str_starts_with($imageUrl, 'http')
                ? $imageUrl
                : 'data:image/jpeg;base64,' . base64_encode(file_get_contents($imageUrl));

            $response = Http::timeout(60)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type' => 'application/json',
                ])
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => 'gpt-4o',
                    'messages' => [
                        [
                            'role' => 'user',
                            'content' => [
                                ['type' => 'text', 'text' => $prompt],
                                ['type' => 'image_url', 'image_url' => ['url' => $imageData]],
                            ],
                        ],
                    ],
                    'response_format' => ['type' => 'json_object'],
                    'max_tokens' => 500,
                ]);

            if (!$response->successful()) {
                Log::error('OpenAI Vision analysis failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return self::getEmptyResult('Vision API call failed');
            }

            $result = $response->json();
            $content = $result['choices'][0]['message']['content'] ?? '{}';
            $parsed = json_decode($content, true) ?? [];

            return [
                'detected_intent' => $parsed['detected_intent'] ?? 'unknown',
                'extracted_text' => $parsed['extracted_text'] ?? '',
                'product_matches' => $parsed['product_matches'] ?? [],
                'confidence' => $parsed['confidence'] ?? 0.5,
                'raw_analysis' => $parsed['description'] ?? '',
            ];

        } catch (\Exception $e) {
            Log::error('Image analysis error', ['error' => $e->getMessage()]);
            return self::getEmptyResult($e->getMessage());
        }
    }

    /**
     * Analyze image using Gemini 1.5 Flash.
     */
    private static function analyzeWithGemini(string $imageUrl, string $prompt): array
    {
        $apiKey = env('GEMINI_API_KEY');

        if (!$apiKey) {
            return self::getEmptyResult('Gemini API key not configured');
        }

        try {
            $imageData = str_starts_with($imageUrl, 'http')
                ? file_get_contents($imageUrl)
                : file_get_contents($imageUrl);

            $base64Image = base64_encode($imageData);

            $response = Http::timeout(60)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                ])
                ->post("https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key={$apiKey}", [
                    'contents' => [
                        [
                            'parts' => [
                                ['text' => $prompt],
                                [
                                    'inline_data' => [
                                        'mime_type' => 'image/jpeg',
                                        'data' => $base64Image,
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'generationConfig' => [
                        'responseMimeType' => 'application/json',
                    ],
                ]);

            if (!$response->successful()) {
                Log::error('Gemini Vision analysis failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return self::getEmptyResult('Gemini Vision API call failed');
            }

            $result = $response->json();
            $content = $result['candidates'][0]['content']['parts'][0]['text'] ?? '{}';
            $parsed = json_decode($content, true) ?? [];

            return [
                'detected_intent' => $parsed['detected_intent'] ?? 'unknown',
                'extracted_text' => $parsed['extracted_text'] ?? '',
                'product_matches' => $parsed['product_matches'] ?? [],
                'confidence' => $parsed['confidence'] ?? 0.5,
                'raw_analysis' => $parsed['description'] ?? '',
            ];

        } catch (\Exception $e) {
            Log::error('Gemini image analysis error', ['error' => $e->getMessage()]);
            return self::getEmptyResult($e->getMessage());
        }
    }

    /**
     * Get empty result structure.
     */
    private static function getEmptyResult(string $error): array
    {
        return [
            'detected_intent' => 'unknown',
            'extracted_text' => '',
            'product_matches' => [],
            'confidence' => 0,
            'raw_analysis' => '',
            'error' => $error,
        ];
    }

    /**
     * Check if image understanding is available.
     */
    public static function isAvailable(): bool
    {
        return !empty(env('OPENAI_API_KEY')) || !empty(env('GEMINI_API_KEY'));
    }
}
