<?php

namespace App\Services;

use App\Exceptions\AiProviderRequestException;
use App\Models\BusinessProfile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class EmbeddingsService
{
    // Fallback order: OpenAI → Gemini → Claude → Groq
    // Note: Claude and Groq do not have native embeddings APIs;
    // they fall back to OpenAI-compatible endpoints where available.
    private const PROVIDER_PRIORITY = ['openai', 'gemini', 'claude', 'groq'];
    private const OUTPUT_DIMENSIONALITY = 768;

    private string $activeProvider = '';
    private string $apiKey = '';
    private string $model = '';
    private string $baseUrl = '';

    public function __construct()
    {
        $config = app(AiProviderConfiguration::class);
        foreach (self::PROVIDER_PRIORITY as $provider) {
            $key = $config->key($provider);
            if ($key !== '') {
                $this->activeProvider = $provider;
                $this->apiKey = $key;
                [$this->baseUrl, $this->model] = $this->providerConfig($provider);
                Log::info("EmbeddingsService: using provider [{$provider}] for embeddings");
                return;
            }
        }
        Log::warning('EmbeddingsService: no embedding provider is configured; semantic knowledge search is unavailable');
    }

    private function providerConfig(string $provider): array
    {
        return match ($provider) {
            'openai' => ['https://api.openai.com/v1', 'text-embedding-3-small'],
            'gemini' => ['https://generativelanguage.googleapis.com/v1beta', 'gemini-embedding-001'],
            // Claude has no embeddings API — fall through to OpenAI-compatible Groq endpoint
            'claude' => ['https://api.openai.com/v1', 'text-embedding-3-small'],
            // Groq does not expose an embeddings endpoint; we use their openai-compatible chat base
            // but log a warning since embeddings may not work
            'groq'   => ['https://api.groq.com/openai/v1', 'nomic-embed-text'],
            default  => ['https://api.openai.com/v1', 'text-embedding-3-small'],
        };
    }

    // -------------------------------------------------------------------------
    // Single chunk
    // -------------------------------------------------------------------------

    public function embedChunk(string $text, ?BusinessProfile $business = null, ?string $operationId = null): ?array
    {
        if (trim($text) === '') return null;
        if ($this->apiKey === '') return null;
        if (!$business) throw new \RuntimeException('Embedding generation requires an authorized business credit wallet.');

        $estimate = max(1, (int) ceil(mb_strlen($text) / 4));
        $provider = $this->activeProvider;
        $model    = $this->model;

        $result = app(AiGateway::class)->execute(
            $business, 'knowledge_embedding', $provider, $model, $estimate, 0,
            function () use ($text, $estimate, $provider, $model): array {
                return $provider === 'gemini'
                    ? $this->geminiEmbedSingle($text, $estimate, $model)
                    : $this->openaiEmbedSingle($text, $estimate, $model);
            },
            ['modality' => 'embedding'],
            $operationId
        );

        return $result['embedding'] ?? null;
    }

    // -------------------------------------------------------------------------
    // Batch
    // -------------------------------------------------------------------------

    public function embedBatch(array $chunks, ?BusinessProfile $business = null, ?string $operationId = null): array
    {
        if (!$chunks || $this->apiKey === '') return [];
        if (!$business) throw new \RuntimeException('Embedding generation requires an authorized business credit wallet.');

        $estimate = max(1, (int) ceil(collect($chunks)->sum(fn ($c) => mb_strlen((string) $c) / 4)));
        $provider = $this->activeProvider;
        $model    = $this->model;

        $result = app(AiGateway::class)->execute(
            $business, 'knowledge_embedding', $provider, $model, $estimate, 0,
            function () use ($chunks, $estimate, $provider, $model): array {
                return $provider === 'gemini'
                    ? $this->geminiEmbedBatch($chunks, $estimate, $model)
                    : $this->openaiEmbedBatch($chunks, $estimate, $model);
            },
            ['modality' => 'embedding', 'chunk_count' => count($chunks)],
            $operationId
        );

        return $result['embeddings'] ?? [];
    }

    // -------------------------------------------------------------------------
    // Gemini helpers
    // -------------------------------------------------------------------------

    private function geminiEmbedSingle(string $text, int $estimate, string $model): array
    {
        $response = Http::timeout(15)->connectTimeout(10)
            ->post("{$this->baseUrl}/models/{$model}:embedContent?key={$this->apiKey}", [
                'model'               => "models/{$model}",
                'content'             => ['parts' => [['text' => $text]]],
                'outputDimensionality' => self::OUTPUT_DIMENSIONALITY,
            ]);
        if (!$response->successful()) throw new AiProviderRequestException('Gemini embeddings', $response->status());
        $data   = $response->json();
        $vector = $data['embedding']['values'] ?? [];
        return [
            'content'   => json_encode($vector),
            'embedding' => $vector,
            'provider'  => 'gemini',
            'model'     => $model,
            'usage'     => ['input_tokens' => (int) data_get($data, 'usageMetadata.totalTokenCount', $estimate), 'output_tokens' => 0],
        ];
    }

    private function geminiEmbedBatch(array $chunks, int $estimate, string $model): array
    {
        $requests = array_map(
            fn ($chunk) => ['model' => "models/{$model}", 'content' => ['parts' => [['text' => $chunk]]], 'outputDimensionality' => self::OUTPUT_DIMENSIONALITY],
            $chunks
        );
        $response = Http::timeout(20)->connectTimeout(10)
            ->post("{$this->baseUrl}/models/{$model}:batchEmbedContents?key={$this->apiKey}", ['requests' => $requests]);
        if (!$response->successful()) throw new AiProviderRequestException('Gemini batch embeddings', $response->status());
        $data       = $response->json();
        $embeddings = [];
        foreach (($data['embeddings'] ?? []) as $i => $emb) $embeddings[$i] = $emb['values'] ?? [];
        return [
            'content'    => json_encode($embeddings),
            'embeddings' => $embeddings,
            'provider'   => 'gemini',
            'model'      => $model,
            'usage'      => ['input_tokens' => (int) data_get($data, 'usageMetadata.totalTokenCount', $estimate), 'output_tokens' => 0],
        ];
    }

    // -------------------------------------------------------------------------
    // OpenAI-compatible helpers (OpenAI, Groq)
    // -------------------------------------------------------------------------

    private function openaiEmbedSingle(string $text, int $estimate, string $model): array
    {
        $response = Http::timeout(15)->connectTimeout(10)
            ->withToken($this->apiKey)
            ->post("{$this->baseUrl}/embeddings", [
                'model' => $model,
                'input' => $text,
            ]);
        if (!$response->successful()) throw new AiProviderRequestException("{$this->activeProvider} embeddings", $response->status());
        $data   = $response->json();
        $vector = $data['data'][0]['embedding'] ?? [];
        return [
            'content'   => json_encode($vector),
            'embedding' => $vector,
            'provider'  => $this->activeProvider,
            'model'     => $model,
            'usage'     => ['input_tokens' => (int) data_get($data, 'usage.prompt_tokens', $estimate), 'output_tokens' => 0],
        ];
    }

    private function openaiEmbedBatch(array $chunks, int $estimate, string $model): array
    {
        $response = Http::timeout(20)->connectTimeout(10)
            ->withToken($this->apiKey)
            ->post("{$this->baseUrl}/embeddings", [
                'model' => $model,
                'input' => array_values($chunks),
            ]);
        if (!$response->successful()) throw new AiProviderRequestException("{$this->activeProvider} batch embeddings", $response->status());
        $data       = $response->json();
        $embeddings = [];
        foreach (($data['data'] ?? []) as $item) {
            $index              = $item['index'] ?? count($embeddings);
            $embeddings[$index] = $item['embedding'] ?? [];
        }
        ksort($embeddings);
        return [
            'content'    => json_encode($embeddings),
            'embeddings' => $embeddings,
            'provider'   => $this->activeProvider,
            'model'      => $model,
            'usage'      => ['input_tokens' => (int) data_get($data, 'usage.prompt_tokens', $estimate), 'output_tokens' => 0],
        ];
    }
}
