<?php

namespace App\Services;

use App\Exceptions\AiProviderRequestException;
use App\Models\BusinessProfile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ImageUnderstandingService
{
    public static function analyzeImage(string $imagePath, string $prompt = 'Describe this image and identify any products or receipts', ?string $diskName = null, ?BusinessProfile $business = null, ?string $operationId = null): array
    {
        $resolved = self::resolveStoredImagePath($imagePath, $diskName);
        if (!$resolved || !($bytes = @file_get_contents($resolved))) return self::unavailable('Image file could not be read safely.');
        if (!$business) return self::unavailable('Image analysis requires an authorized business credit wallet.');

        $defaultProvider = (string) config('services.vision.provider', 'openai');
        $defaultModel = $defaultProvider === 'gemini'
            ? (string) config('services.vision.gemini_model', 'gemini-2.5-flash')
            : (string) config('services.vision.openai_model', 'gpt-4o');
        $route = app(AiRouter::class)->resolve($business, 'image_understanding', $defaultProvider, $defaultModel, ['image', 'json']);
        $provider = (string) $route['provider'];
        $model = (string) $route['model'];
        $mime = self::detectImageMimeType($resolved);
        return app(AiGateway::class)->execute(
            $business, 'image_understanding', $provider, $model,
            max(256, (int) ceil(mb_strlen($prompt) / 4) + 1024), 500,
            function () use ($route, $bytes, $mime, $prompt): array {
                $candidates = [['provider' => $route['provider'], 'model_key' => $route['model']]];
                foreach (($route['fallback_models'] ?? []) as $fallback) $candidates[] = ['provider' => $fallback['provider'], 'model_key' => $fallback['model_key']];
                $last = null;
                foreach (array_slice($candidates, 0, 1 + max(0, (int) ($route['max_retries'] ?? 1))) as $candidate) {
                    $candidateProvider = (string) $candidate['provider'];
                    $candidateModel = (string) $candidate['model_key'];
                    if (!in_array($candidateProvider, ['openai', 'gemini'], true)) {
                        $last = new AiProviderRequestException(ucfirst($candidateProvider), 400, 'This provider does not support image understanding.');
                        continue;
                    }
                    $apiKey = app(AiProviderConfiguration::class)->key($candidateProvider);
                    if ($apiKey === '') throw new AiProviderRequestException(ucfirst($candidateProvider), 401, "{$candidateProvider} API key is not configured.");
                    try {
                        $result = $candidateProvider === 'gemini'
                            ? self::callGemini($bytes, $mime, $prompt, $apiKey, $candidateModel)
                            : self::callOpenAi($bytes, $mime, $prompt, $apiKey, $candidateModel);
                        $result['provider'] = $candidateProvider;
                        $result['model'] = $candidateModel;
                        return $result;
                    } catch (AiProviderRequestException $exception) {
                        app(AiGateway::class)->reportProviderFailure($candidateProvider, $candidateModel, $exception);
                        $last = $exception;
                        if (!$exception->isRetryableUnbilled()) break;
                    }
                }
                throw $last ?? new \RuntimeException('No eligible image-understanding model is available.');
            },
            ['modality' => 'image', 'image_units' => 1, 'routing_source' => $route['source'], 'routing_rule_id' => $route['rule_id'], 'fallback_model_ids' => $route['fallback_model_ids'] ?? []], $operationId,
        )['analysis'] ?? self::unavailable('Image analysis response was empty.');
    }

    public static function isAvailable(): bool
    {
        return match ((string) config('services.vision.provider', 'openai')) {
            'gemini' => app(AiProviderConfiguration::class)->isAvailable('gemini'),
            default => app(AiProviderConfiguration::class)->isAvailable('openai'),
        };
    }

    private static function callOpenAi(string $bytes, string $mime, string $prompt, string $key, string $model): array
    {
        $response = Http::timeout(60)->withToken($key)->post('https://api.openai.com/v1/chat/completions', [
            'model' => $model,
            'messages' => [['role' => 'user', 'content' => [
                ['type' => 'text', 'text' => $prompt],
                ['type' => 'image_url', 'image_url' => ['url' => 'data:' . $mime . ';base64,' . base64_encode($bytes)]],
            ]]],
            'response_format' => ['type' => 'json_object'], 'max_tokens' => 500,
        ]);
        if (!$response->successful()) throw new AiProviderRequestException('OpenAI Vision', $response->status());
        $content = (string) $response->json('choices.0.message.content', '{}');
        return ['content' => $content, 'analysis' => self::normalizeResponse(json_decode($content, true) ?: []), 'provider' => 'openai', 'model' => $model,
            'usage' => ['input_tokens' => (int) $response->json('usage.prompt_tokens', 0), 'output_tokens' => (int) $response->json('usage.completion_tokens', 0), 'cached_tokens' => (int) $response->json('usage.prompt_tokens_details.cached_tokens', 0)]];
    }

    private static function callGemini(string $bytes, string $mime, string $prompt, string $key, string $model): array
    {
        $response = Http::timeout(60)->post("https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=" . urlencode($key), [
            'contents' => [[
                'parts' => [
                    ['text' => $prompt],
                    ['inline_data' => ['mime_type' => $mime, 'data' => base64_encode($bytes)]],
                ],
            ]],
            'generationConfig' => ['responseMimeType' => 'application/json', 'maxOutputTokens' => 500],
        ]);
        if (!$response->successful()) throw new AiProviderRequestException('Gemini Vision', $response->status());
        $content = (string) $response->json('candidates.0.content.parts.0.text', '{}');
        return ['content' => $content, 'analysis' => self::normalizeResponse(json_decode($content, true) ?: []), 'provider' => 'gemini', 'model' => $model,
            'usage' => ['input_tokens' => (int) $response->json('usageMetadata.promptTokenCount', 0), 'output_tokens' => (int) $response->json('usageMetadata.candidatesTokenCount', 0), 'cached_tokens' => (int) $response->json('usageMetadata.cachedContentTokenCount', 0)]];
    }

    private static function resolveStoredImagePath(string $imagePath, ?string $diskName): ?string
    {
        $storage = app(IncomingMediaStorage::class); $diskName ??= $storage->diskName(); $decoded = rawurldecode($imagePath);
        if ($decoded === '' || str_contains($decoded, "\0") || preg_match('#^https?://#i', $decoded)) return null;
        if (!self::isAbsolutePath($decoded)) return $storage->absolutePath($decoded, $diskName);
        $resolved = realpath($decoded); $root = realpath(Storage::disk($diskName)->path(''));
        return $resolved && $root && is_file($resolved) && str_starts_with($resolved, rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR) ? $resolved : null;
    }

    private static function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, DIRECTORY_SEPARATOR) || (bool) preg_match('/^[A-Za-z]:[\\\\\/]/', $path);
    }

    private static function normalizeResponse(array $parsed): array
    {
        return ['status' => 'processed', 'detected_intent' => $parsed['detected_intent'] ?? 'unknown', 'extracted_text' => $parsed['extracted_text'] ?? '', 'product_matches' => $parsed['product_matches'] ?? [], 'confidence' => $parsed['confidence'] ?? 0.5, 'raw_analysis' => $parsed['description'] ?? ''];
    }

    private static function unavailable(string $reason): array
    {
        Log::notice('Image analysis unavailable', ['reason' => $reason]);
        return ['status' => 'unavailable', 'detected_intent' => 'unknown', 'extracted_text' => '', 'product_matches' => [], 'confidence' => 0, 'raw_analysis' => '', 'error' => 'Image analysis unavailable: ' . $reason];
    }

    private static function detectImageMimeType(string $path): string
    {
        $mime = function_exists('mime_content_type') ? mime_content_type($path) : false;
        return in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true) ? $mime : 'image/jpeg';
    }
}
