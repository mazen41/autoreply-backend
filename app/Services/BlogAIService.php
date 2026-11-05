<?php

namespace App\Services;

use App\Models\BusinessProfile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class BlogAIService
{
    /** Billable content generation always requires a business wallet and central gateway. */
    public static function callAI(BusinessProfile $business, string $prompt, array $context = [], ?string $systemPrompt = null, ?string $operationKey = null): ?string
    {
        try {
            $messages = array_map(static fn (array $message): array => [
                'role' => ($message['role'] ?? 'user') === 'assistant' ? 'assistant' : 'user',
                'content' => (string) ($message['content'] ?? ''),
            ], $context);
            $messages[] = ['role' => 'user', 'content' => $prompt];
            $result = app(AiGateway::class)->chat(
                $business,
                'campaign_generation',
                $systemPrompt ?: 'Create clear, accurate business content using only the supplied information.',
                $messages,
                (int) config('services.ai.max_tokens', 2048),
                'blog-content:' . ($operationKey ?: Str::uuid()),
                ['source' => 'blog_ai'],
            );
            return trim((string) ($result['content'] ?? '')) ?: null;
        } catch (\Throwable $exception) {
            Log::warning('Blog AI generation failed', ['business_id' => $business->id, 'exception_type' => get_class($exception)]);
            return null;
        }
    }

    public static function getProviderInfo(): array
    {
        return [
            'routing' => 'admin_managed',
            'configured_provider' => config('services.ai.provider', 'groq'),
            'configured_model' => config('services.ai.model', config('services.groq.model')),
        ];
    }
}
