<?php

namespace App\Services;

use App\Exceptions\AiProviderRequestException;
use App\Models\BusinessProfile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class EmbeddingsService
{
    private string $apiKey;
    private string $baseUrl = 'https://generativelanguage.googleapis.com/v1beta';
    private string $model = 'gemini-embedding-001';
    private const OUTPUT_DIMENSIONALITY = 768;

    public function __construct()
    {
        $this->apiKey = app(AiProviderConfiguration::class)->key('gemini');
        if ($this->apiKey === '') Log::warning('EmbeddingsService: Gemini embedding key is not configured; semantic knowledge search is unavailable');
    }

    public function embedChunk(string $text, ?BusinessProfile $business = null, ?string $operationId = null): ?array
    {
        if (trim($text) === '') return null;
        if ($this->apiKey === '') return null;
        if (!$business) throw new \RuntimeException('Embedding generation requires an authorized business credit wallet.');
        $estimate = max(1, (int) ceil(mb_strlen($text) / 4));
        $result = app(AiGateway::class)->execute($business, 'knowledge_embedding', 'gemini', $this->model, $estimate, 0,
            function () use ($text, $estimate): array {
                $response = Http::timeout(15)->connectTimeout(10)->post("{$this->baseUrl}/models/{$this->model}:embedContent?key={$this->apiKey}", [
                    'model' => "models/{$this->model}", 'content' => ['parts' => [['text' => $text]]],
                    'outputDimensionality' => self::OUTPUT_DIMENSIONALITY,
                ]);
                if (!$response->successful()) throw new AiProviderRequestException('Gemini embeddings', $response->status());
                $data = $response->json(); $vector = $data['embedding']['values'] ?? [];
                return ['content' => json_encode($vector), 'embedding' => $vector, 'provider' => 'gemini', 'model' => $this->model,
                    'usage' => ['input_tokens' => (int) data_get($data, 'usageMetadata.totalTokenCount', $estimate), 'output_tokens' => 0]];
            }, ['modality' => 'embedding'], $operationId);
        return $result['embedding'] ?? null;
    }

    public function embedBatch(array $chunks, ?BusinessProfile $business = null, ?string $operationId = null): array
    {
        if (!$chunks || $this->apiKey === '') return [];
        if (!$business) throw new \RuntimeException('Embedding generation requires an authorized business credit wallet.');
        $estimate = max(1, (int) ceil(collect($chunks)->sum(fn ($chunk) => mb_strlen((string) $chunk) / 4)));
        $result = app(AiGateway::class)->execute($business, 'knowledge_embedding', 'gemini', $this->model, $estimate, 0,
            function () use ($chunks, $estimate): array {
                $requests = array_map(fn ($chunk) => ['model' => "models/{$this->model}", 'content' => ['parts' => [['text' => $chunk]]], 'outputDimensionality' => self::OUTPUT_DIMENSIONALITY], $chunks);
                $response = Http::timeout(20)->connectTimeout(10)->post("{$this->baseUrl}/models/{$this->model}:batchEmbedContents?key={$this->apiKey}", ['requests' => $requests]);
                if (!$response->successful()) throw new AiProviderRequestException('Gemini batch embeddings', $response->status());
                $data = $response->json(); $embeddings = [];
                foreach (($data['embeddings'] ?? []) as $index => $embedding) $embeddings[$index] = $embedding['values'] ?? [];
                return ['content' => json_encode($embeddings), 'embeddings' => $embeddings, 'provider' => 'gemini', 'model' => $this->model,
                    'usage' => ['input_tokens' => (int) data_get($data, 'usageMetadata.totalTokenCount', $estimate), 'output_tokens' => 0]];
            }, ['modality' => 'embedding', 'chunk_count' => count($chunks)], $operationId);
        return $result['embeddings'] ?? [];
    }
}
