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

        // Use the GA endpoint (v1), NOT the deprecated v1beta.
        // Error "text-embedding-004 is not found for API version v1beta" confirms v1beta is wrong.
        $this->baseUrl = 'https://generativelanguage.googleapis.com/v1';
        $this->model   = 'text-embedding-004';

        if (empty($this->apiKey)) {
            Log::error('EmbeddingsService: GEMINI_API_KEY is not configured — all embedding calls will fail');
        }
    }

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
