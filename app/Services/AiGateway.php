<?php

namespace App\Services;

use App\Models\AiModelPrice;
use App\Models\AiUsageLog;
use App\Models\BusinessProfile;
use App\Exceptions\AiProviderRequestException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AiGateway
{
    public function __construct(private readonly AiCreditManager $credits) {}

    public static function stableOperationId(string $key): string
    {
        $hex = substr(hash('sha256', $key), 0, 32);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-5' . substr($hex, 13, 3) . '-a' . substr($hex, 17, 3) . '-' . substr($hex, 20, 12);
    }

    /** Common text-generation entry point for business AI features. */
    public function chat(BusinessProfile $business, string $task, string $systemPrompt, array $messages, int $maxOutputTokens, string $operationKey, array $metadata = []): array
    {
        $route = app(AiRouter::class)->resolve($business, $task, null, null);
        $inputTokens = max(1, (int) ceil(collect($messages)->sum(fn ($message) => mb_strlen((string) ($message['content'] ?? '')) / 4) + mb_strlen($systemPrompt) / 4));
        $operationId = self::stableOperationId($operationKey);
        $result = $this->execute($business, $task, $route['provider'], $route['model'], $inputTokens, $maxOutputTokens,
            function () use ($route, $systemPrompt, $messages, $maxOutputTokens, $inputTokens, $task): array {
                $models = [['provider' => $route['provider'], 'model_key' => $route['model']]];
                foreach (($route['fallback_models'] ?? []) as $fallback) $models[] = ['provider' => $fallback['provider'], 'model_key' => $fallback['model_key']];
                $last = null;
                foreach (array_slice($models, 0, 1 + max(0, (int) ($route['max_retries'] ?? 1))) as $candidate) {
                    try {
                        $response = $this->requestChat((string) $candidate['provider'], (string) $candidate['model_key'], $systemPrompt, $messages, $maxOutputTokens, isset($route['max_latency_ms']) ? (int) $route['max_latency_ms'] : null);
                        $response['provider'] = $candidate['provider'];
                        $response['model'] = $candidate['model_key'];
                        return $response;
                    } catch (AiProviderRequestException $exception) {
                        $this->recordProviderFailure((string) $candidate['provider'], (string) $candidate['model_key'], $exception);
                        $last = $exception;
                        if (!$exception->isRetryableUnbilled()) break;
                    }
                }
                throw $last ?? new \RuntimeException('No eligible AI provider is available.');
            }, array_merge($metadata, ['routing_source' => $route['source'], 'routing_rule_id' => $route['rule_id'], 'fallback_model_ids' => $route['fallback_model_ids'] ?? []]), $operationId);
        $this->recordProviderSuccess((string) $result['provider'], (string) $result['model']);
        return $result;
    }

    private function requestChat(string $provider, string $model, string $systemPrompt, array $messages, int $maxOutputTokens, ?int $maxLatencyMs = null): array
    {
        $key = app(AiProviderConfiguration::class)->key($provider);
        if ($key === '') throw new AiProviderRequestException(ucfirst($provider), 401, "{$provider} API key is not configured.");
        $providerTimeout = app(AiProviderConfiguration::class)->timeoutSeconds($provider);
        $timeout = $maxLatencyMs !== null ? min($providerTimeout, max(1, (int) ceil($maxLatencyMs / 1000))) : $providerTimeout;
        if ($provider === 'claude') {
            $turns = array_map(fn ($message) => ['role' => ($message['role'] ?? '') === 'assistant' ? 'assistant' : 'user', 'content' => (string) ($message['content'] ?? '')], $messages);
            $response = Http::timeout($timeout)->withHeaders(['x-api-key' => $key, 'anthropic-version' => '2023-06-01'])->post('https://api.anthropic.com/v1/messages', ['model' => $model, 'max_tokens' => $maxOutputTokens, 'system' => $systemPrompt, 'messages' => $turns]);
            if (!$response->successful()) throw new AiProviderRequestException('Claude', $response->status());
            return ['content' => (string) $response->json('content.0.text', ''), 'usage' => ['input_tokens' => (int) $response->json('usage.input_tokens', 0), 'output_tokens' => (int) $response->json('usage.output_tokens', 0), 'cached_tokens' => (int) $response->json('usage.cache_read_input_tokens', 0)]];
        }
        if ($provider === 'gemini') {
            $contents = [];
            foreach ($messages as $message) $contents[] = ['role' => ($message['role'] ?? '') === 'assistant' ? 'model' : 'user', 'parts' => [['text' => (string) ($message['content'] ?? '')]]];
            $response = Http::timeout($timeout)->post("https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=" . urlencode($key), ['contents' => $contents, 'systemInstruction' => ['parts' => [['text' => $systemPrompt]]], 'generationConfig' => ['maxOutputTokens' => $maxOutputTokens, 'temperature' => (float) config('services.ai.temperature', 0.7)]]);
            if (!$response->successful()) throw new AiProviderRequestException('Gemini', $response->status());
            return ['content' => (string) $response->json('candidates.0.content.parts.0.text', ''), 'usage' => ['input_tokens' => (int) $response->json('usageMetadata.promptTokenCount', 0), 'output_tokens' => (int) $response->json('usageMetadata.candidatesTokenCount', 0), 'cached_tokens' => (int) $response->json('usageMetadata.cachedContentTokenCount', 0)]];
        }
        $turns = array_merge([['role' => 'system', 'content' => $systemPrompt]], array_map(fn ($message) => ['role' => ($message['role'] ?? '') === 'assistant' ? 'assistant' : 'user', 'content' => (string) ($message['content'] ?? '')], $messages));
        $url = $provider === 'groq' ? 'https://api.groq.com/openai/v1/chat/completions' : 'https://api.openai.com/v1/chat/completions';
        $response = Http::timeout($timeout)->withToken($key)->post($url, ['model' => $model, 'messages' => $turns, 'temperature' => (float) config('services.ai.temperature', 0.7), 'max_tokens' => $maxOutputTokens]);
        if (!$response->successful()) throw new AiProviderRequestException(ucfirst($provider), $response->status());
        return ['content' => (string) $response->json('choices.0.message.content', ''), 'usage' => ['input_tokens' => (int) $response->json('usage.prompt_tokens', 0), 'output_tokens' => (int) $response->json('usage.completion_tokens', 0), 'cached_tokens' => (int) $response->json('usage.prompt_tokens_details.cached_tokens', 0)]];
    }

    private function recordProviderFailure(string $provider, string $model, AiProviderRequestException $exception): void
    {
        $status = $exception->getCode();
        if (!in_array($status, [404, 429], true) && $status < 500) return;
        if (!DB::getSchemaBuilder()->hasTable('ai_provider_health')) return;
        $health = DB::table('ai_provider_health')->where('provider', $provider)->where('model_key', $model)->first();
        $failures = (int) ($health->consecutive_failures ?? 0) + 1;
        DB::table('ai_provider_health')->updateOrInsert(['provider' => $provider, 'model_key' => $model], ['consecutive_failures' => $failures, 'last_error_code' => $status, 'last_failure_at' => now(), 'available_after' => $failures >= 5 ? now()->addMinute() : null, 'updated_at' => now(), 'created_at' => $health->created_at ?? now()]);
    }

    private function recordProviderSuccess(string $provider, string $model): void
    {
        if (DB::getSchemaBuilder()->hasTable('ai_provider_health')) DB::table('ai_provider_health')->updateOrInsert(['provider' => $provider, 'model_key' => $model], ['consecutive_failures' => 0, 'last_error_code' => null, 'last_success_at' => now(), 'available_after' => null, 'updated_at' => now(), 'created_at' => now()]);
    }

    public function reportProviderFailure(string $provider, string $model, AiProviderRequestException $exception): void
    {
        $this->recordProviderFailure($provider, $model, $exception);
    }

    /** The billable boundary for text generation. Provider calls must pass here. */
    public function execute(
        BusinessProfile $business,
        string $task,
        string $provider,
        string $model,
        int $estimatedInputTokens,
        int $maxOutputTokens,
        callable $operation,
        array $metadata = [],
        ?string $operationId = null,
    ): array {
        $operationId ??= (string) Str::uuid();
        // Ensure the wallet exists and the current billing-cycle grant is applied before reserving.
        $this->credits->walletFor($business);
        $price = AiModelPrice::query()->where('provider', $provider)->where('model_key', $model)->where('is_active', true)->first();
        if (!$price || ((float) $price->input_price_per_million <= 0 && (float) $price->output_price_per_million <= 0 && (float) ($price->audio_price_per_minute ?? 0) <= 0 && (float) ($price->image_price_per_unit ?? 0) <= 0 && (float) ($price->audio_output_price_per_million_chars ?? 0) <= 0)) {
            throw new \RuntimeException("AI pricing is not configured for {$provider}/{$model}; ask an administrator to add the model price.");
        }

        $businessSettings = DB::table('ai_business_settings')->where('business_id', $business->id)->first();
        $mode = $businessSettings?->operating_mode ?: 'auto';
        try {
            [$reservation, $usage] = DB::transaction(function () use ($business, $price, $estimatedInputTokens, $maxOutputTokens, $operationId, $task, $metadata, $mode, $provider, $model): array {
                $existing = AiUsageLog::query()->where('operation_id', $operationId)->lockForUpdate()->first();
                if ($existing) {
                    if ($existing->status === 'succeeded' && $existing->response_payload) return [0, $existing];
                    throw new \RuntimeException('This AI operation is already being processed or needs billing reconciliation.');
                }
                $reservationPrice = $price;
                $fallbackIds = array_values(array_map('intval', $metadata['fallback_model_ids'] ?? []));
                if ($fallbackIds) {
                    $settings = $this->credits->settings();
                    $candidatePrices = AiModelPrice::query()->whereIn('id', $fallbackIds)->where('is_active', true)->get();
                    foreach ($candidatePrices as $candidatePrice) {
                        if ($this->credits->calculateCredits($candidatePrice, max(0, $estimatedInputTokens), max(0, $maxOutputTokens), $settings, 0, max(0, (int) ($metadata['estimated_audio_seconds'] ?? 0)), max(0, (int) ($metadata['image_units'] ?? 0)), max(0, (int) ($metadata['audio_output_chars'] ?? 0)))
                            > $this->credits->calculateCredits($reservationPrice, max(0, $estimatedInputTokens), max(0, $maxOutputTokens), $settings, 0, max(0, (int) ($metadata['estimated_audio_seconds'] ?? 0)), max(0, (int) ($metadata['image_units'] ?? 0)), max(0, (int) ($metadata['audio_output_chars'] ?? 0)))) {
                            $reservationPrice = $candidatePrice;
                        }
                    }
                }
                $reservation = $this->credits->reserve($business, $reservationPrice, max(0, $estimatedInputTokens), max(0, $maxOutputTokens), $operationId, $task, max(0, (int) ($metadata['estimated_audio_seconds'] ?? 0)), max(0, (int) ($metadata['image_units'] ?? 0)), max(0, (int) ($metadata['audio_output_chars'] ?? 0)));
                $usage = AiUsageLog::query()->create([
                    'operation_id' => $operationId,
                    'business_id' => $business->id,
                    'conversation_id' => $metadata['conversation_id'] ?? null,
                    'assistant_id' => $metadata['assistant_id'] ?? null,
                    'task_type' => $task,
                    'provider' => $provider,
                    'model_key' => $model,
                    'operating_mode' => $mode,
                    'status' => 'pending',
                    'metadata' => array_merge($metadata, ['reserved_credits' => $reservation]),
                ]);
                return [$reservation, $usage];
            });
            if ($reservation === 0 && $usage->status === 'succeeded' && $usage->response_payload) {
                return json_decode(Crypt::decryptString($usage->response_payload), true) ?? [];
            }
        } catch (\Illuminate\Database\QueryException $exception) {
            $existing = AiUsageLog::query()->where('operation_id', $operationId)->first();
            if ($existing?->status === 'succeeded' && $existing->response_payload) return json_decode(Crypt::decryptString($existing->response_payload), true) ?? [];
            throw $exception;
        }

        $startedAt = hrtime(true);
        try {
            $result = $operation();
            $actualProvider = (string) ($result['provider'] ?? $provider);
            $actualModel = (string) ($result['model'] ?? $model);
            $usageData = is_array($result['usage'] ?? null) ? $result['usage'] : [];
            $inputTokens = max(0, (int) ($usageData['input_tokens'] ?? $estimatedInputTokens));
            $outputTokens = max(0, (int) ($usageData['output_tokens'] ?? $this->estimateTokens((string) ($result['content'] ?? ''))));
            $cachedTokens = max(0, (int) ($usageData['cached_tokens'] ?? 0));
            $audioSeconds = max(0, (int) ($usageData['audio_seconds'] ?? $metadata['estimated_audio_seconds'] ?? 0));
            $imageUnits = max(0, (int) ($usageData['image_units'] ?? $metadata['image_units'] ?? 0));
            $audioOutputChars = max(0, (int) ($usageData['audio_output_chars'] ?? $metadata['audio_output_chars'] ?? 0));
            $actualPrice = AiModelPrice::query()->where('provider', $actualProvider)->where('model_key', $actualModel)->where('is_active', true)->first();
            if (!$actualPrice) {
                throw new \RuntimeException("Fallback model pricing is not configured for {$actualProvider}/{$actualModel}.");
            }
            $usage->update(['provider' => $actualProvider, 'model_key' => $actualModel, 'latency_ms' => (int) ((hrtime(true) - $startedAt) / 1_000_000), 'metadata' => array_merge($usage->metadata ?? [], $metadata, ['reserved_credits' => $reservation, 'usage_estimated' => empty($usageData)])]);
            // Persist the encrypted result before finalizing the debit. If a worker
            // dies immediately after settlement, an idempotent retry can return the
            // original response without calling the provider or charging twice.
            $result['provider'] = $actualProvider;
            $result['model'] = $actualModel;
            $usage->update(['response_payload' => Crypt::encryptString(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR))]);
            $this->credits->settle($business, $usage->fresh(), $reservation, $inputTokens, $outputTokens, $cachedTokens, $audioSeconds, $imageUnits, $audioOutputChars);
            $this->recordProviderSuccess($actualProvider, $actualModel);
            return $result;
        } catch (\Throwable $exception) {
            if ($exception instanceof AiProviderRequestException) $this->recordProviderFailure($provider, $model, $exception);
            Log::warning('AI gateway operation failed', ['business_id' => $business->id, 'operation_id' => $operationId, 'task_type' => $task, 'provider' => $provider, 'model' => $model, 'exception_type' => get_class($exception), 'http_status' => $exception instanceof AiProviderRequestException ? $exception->getCode() : null, 'usage_status' => $exception instanceof AiProviderRequestException && $exception->isDefinitelyUnbilled() ? 'will_release_reservation' : 'needs_reconciliation']);
            if ($exception instanceof AiProviderRequestException && $exception->isDefinitelyUnbilled()) {
                $this->credits->releaseFailedReservation($usage->fresh(), $reservation, $exception);
            } else {
                $this->credits->markUncertainFailure($usage->fresh(), $reservation, $exception);
            }
            throw $exception;
        }
    }

    private function estimateTokens(string $text): int
    {
        return max(1, (int) ceil(mb_strlen($text) / 4));
    }
}
