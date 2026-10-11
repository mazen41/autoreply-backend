<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ImageUnderstandingService
{
    public static function analyzeImage(
        string $imagePath,
        string $prompt = 'Describe this image and identify any products or receipts',
        ?string $diskName = null,
    ): array {
        $provider = (string) config('services.vision.provider', 'openai');
        $apiKey = match ($provider) {
            'gemini' => (string) config('services.vision.gemini_api_key', ''),
            default => (string) config('services.vision.openai_api_key', ''),
        };

        if ($apiKey === '') {
            return self::unavailable(ucfirst($provider) . ' Vision API key is not configured.');
        }

        $resolvedPath = self::resolveStoredImagePath($imagePath, $diskName);
        if (!$resolvedPath) {
            return self::unavailable('Image path is unavailable or outside the allowed storage root.');
        }

        $imageData = @file_get_contents($resolvedPath);
        if ($imageData === false) {
            return self::unavailable('Stored image could not be read.');
        }

        $mimeType = self::detectImageMimeType($resolvedPath);

        return $provider === 'gemini'
            ? self::analyzeWithGemini($imageData, $mimeType, $prompt, $apiKey)
            : self::analyzeWithOpenAI($imageData, $mimeType, $prompt, $apiKey);
    }

    public static function isAvailable(): bool
    {
        return match ((string) config('services.vision.provider', 'openai')) {
            'gemini' => (string) config('services.vision.gemini_api_key', '') !== '',
            default => (string) config('services.vision.openai_api_key', '') !== '',
        };
    }

    private static function resolveStoredImagePath(string $imagePath, ?string $diskName): ?string
    {
        $storage = app(IncomingMediaStorage::class);
        $diskName ??= $storage->diskName();
        $decoded = rawurldecode($imagePath);

        if ($decoded === '' || str_contains($decoded, "\0") || preg_match('#^https?://#i', $decoded)) {
            return null;
        }

        if (!self::isAbsolutePath($decoded)) {
            return $storage->absolutePath($decoded, $diskName);
        }

        $resolved = realpath($decoded);
        $root = realpath(Storage::disk($diskName)->path(''));
        if (!$resolved || !$root || !is_file($resolved)) {
            return null;
        }

        return str_starts_with($resolved, rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)
            ? $resolved
            : null;
    }

    private static function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, DIRECTORY_SEPARATOR)
            || (bool) preg_match('/^[A-Za-z]:[\\\\\/]/', $path);
    }

    private static function analyzeWithOpenAI(string $imageData, string $mimeType, string $prompt, string $apiKey): array
    {
        try {
            $response = Http::timeout(60)
                ->withToken($apiKey)
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => config('services.vision.openai_model', 'gpt-4o'),
                    'messages' => [[
                        'role' => 'user',
                        'content' => [
                            ['type' => 'text', 'text' => $prompt],
                            ['type' => 'image_url', 'image_url' => [
                                'url' => 'data:' . $mimeType . ';base64,' . base64_encode($imageData),
                            ]],
                        ],
                    ]],
                    'response_format' => ['type' => 'json_object'],
                    'max_tokens' => 500,
                ]);

            if (!$response->successful()) {
                Log::warning('OpenAI Vision analysis failed', ['status' => $response->status()]);
                return self::unavailable('OpenAI Vision API request failed.');
            }

            $content = $response->json('choices.0.message.content', '{}');
            return self::normalizeResponse(json_decode((string) $content, true) ?: []);
        } catch (\Throwable $exception) {
            Log::warning('OpenAI Vision analysis failed', ['exception_type' => get_class($exception)]);
            return self::unavailable('OpenAI Vision analysis failed.');
        }
    }

    private static function analyzeWithGemini(string $imageData, string $mimeType, string $prompt, string $apiKey): array
    {
        try {
            $model = (string) config('services.vision.gemini_model', 'gemini-3.8-flash');
            $response = Http::timeout(60)
                ->post("https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=" . urlencode($apiKey), [
                    'contents' => [[
                        'parts' => [
                            ['text' => $prompt],
                            ['inline_data' => [
                                'mime_type' => $mimeType,
                                'data' => base64_encode($imageData),
                            ]],
                        ],
                    ]],
                    'generationConfig' => ['responseMimeType' => 'application/json'],
                ]);

            if (!$response->successful()) {
                Log::warning('Gemini Vision analysis failed', ['status' => $response->status()]);
                return self::unavailable('Gemini Vision API request failed.');
            }

            $content = $response->json('candidates.0.content.parts.0.text', '{}');
            return self::normalizeResponse(json_decode((string) $content, true) ?: []);
        } catch (\Throwable $exception) {
            Log::warning('Gemini Vision analysis failed', ['exception_type' => get_class($exception)]);
            return self::unavailable('Gemini Vision analysis failed.');
        }
    }

    private static function normalizeResponse(array $parsed): array
    {
        return [
            'detected_intent' => $parsed['detected_intent'] ?? 'unknown',
            'extracted_text' => $parsed['extracted_text'] ?? '',
            'product_matches' => $parsed['product_matches'] ?? [],
            'confidence' => $parsed['confidence'] ?? 0.5,
            'raw_analysis' => $parsed['description'] ?? '',
        ];
    }

    private static function unavailable(string $reason): array
    {
        return [
            'status' => 'unavailable',
            'detected_intent' => 'unknown',
            'extracted_text' => '',
            'product_matches' => [],
            'confidence' => 0,
            'raw_analysis' => '',
            'error' => 'Image analysis unavailable: ' . $reason,
        ];
    }

    private static function detectImageMimeType(string $path): string
    {
        $mimeType = function_exists('mime_content_type') ? mime_content_type($path) : false;

        return in_array($mimeType, ['image/jpeg', 'image/png', 'image/webp'], true)
            ? $mimeType
            : 'image/jpeg';
    }
}
