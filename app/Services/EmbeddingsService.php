<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class EmbeddingsService
{
    private string $apiKey;
    private string $baseUrl;
    private string $model;

    public function __construct()
    {
        // config('services.gemini.api_key') is the canonical key (see config/services.php)
        $this->apiKey = config('services.gemini.api_key') ?? env('GEMINI_API_KEY', '');

        // Priority 3 fix: `text-embedding-004` was permanently shut down by Google on
        // January 14, 2026 (confirmed via ai.google.dev/gemini-api/docs/changelog and
        // the official Gemini Embedding announcement) — that is the actual 404 root
        // cause, NOT the v1/v1beta API version. The replacement model is
        // `gemini-embedding-001`. Every current official Google example for embedContent
        // (ai.google.dev/gemini-api/docs/embeddings, Gemini cookbook, Vertex docs) still
        // uses the v1beta endpoint — embeddings are not on stable v1 — so this reverts
        // the earlier v1beta→v1 change specifically for this service.
        $this->baseUrl = 'https://generativelanguage.googleapis.com/v1beta';
        $this->model   = 'gemini-embedding-001';

        if (empty($this->apiKey)) {
            Log::error('EmbeddingsService: GEMINI_API_KEY is not configured — all embedding calls will fail');
        }
    }

    /**
     * Output dimensionality requested from gemini-embedding-001. 768 matches the
     * dimension text-embedding-004 produced by default, so existing
     * BusinessKnowledgeChunk rows (if any were embedded before this migration)
     * stay array-length-compatible with VectorSearchService's dimension check
     * instead of silently being skipped. NOTE: this does NOT make old and new
     * vectors semantically comparable — they come from different models. Any
     * chunk embedded with text-embedding-004 should be re-embedded (re-run the
     * knowledge file processing job) after this deploy so retrieval quality is
     * consistent across a business's knowledge base.
     */
    private const OUTPUT_DIMENSIONALITY = 768;

    /**
     * Generate an embedding for a single chunk of text
     * 
     * @param string $text
     * @return array<float>|null The embedding array or null on failure
     */
    public function embedChunk(string $text): ?array
    {
        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
            ])->post("{$this->baseUrl}/models/{$this->model}:embedContent?key={$this->apiKey}", [
                'model' => "models/{$this->model}",
                'content' => [
                    'parts' => [
                        ['text' => $text]
                    ]
                ]
            ]);

            if ($response->successful()) {
                $data = $response->json();
                return $data['embedding']['values'] ?? null;
            }

            Log::error('EmbeddingsService: API request failed', [
                'status' => $response->status(),
                'body' => $response->body()
            ]);

            return null;

        } catch (\Exception $e) {
            Log::error('EmbeddingsService: Exception during embedding', [
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Generate embeddings for multiple chunks in one API call
     * Note: Gemini API batchEmbedContents allows multiple requests in one call
     * 
     * @param array<string> $chunks
     * @return array<array<float>> Array of embeddings, keyed by chunk index
     */
    public function embedBatch(array $chunks): array
    {
        if (empty($chunks)) {
            return [];
        }

        try {
            $requests = [];
            foreach ($chunks as $chunk) {
                $requests[] = [
                    'model' => "models/{$this->model}",
                    'content' => [
                        'parts' => [
                            ['text' => $chunk]
                        ]
                    ]
                ];
            }

            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
            ])->post("{$this->baseUrl}/models/{$this->model}:batchEmbedContents?key={$this->apiKey}", [
                'requests' => $requests
            ]);

            if ($response->successful()) {
                $data = $response->json();
                $embeddings = [];
                
                if (isset($data['embeddings']) && is_array($data['embeddings'])) {
                    foreach ($data['embeddings'] as $index => $emb) {
                        $embeddings[$index] = $emb['values'] ?? [];
                    }
                }
                return $embeddings;
            }

            Log::error('EmbeddingsService: Batch API request failed', [
                'status' => $response->status(),
                'body' => $response->body()
            ]);

            return [];

        } catch (\Exception $e) {
            Log::error('EmbeddingsService: Exception during batch embedding', [
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }
}
