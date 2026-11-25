<?php

namespace App\Services;

use App\Models\BusinessKnowledgeChunk;
use Illuminate\Support\Facades\Log;

class VectorSearchService
{
    private array $lastSearchStats = [];

    /**
     * Search for the most semantically relevant chunks given a query embedding
     *
     * @param array<float> $queryEmbedding The embedding of the search query
     * @param int $businessProfileId The ID of the business profile (tenant isolation)
     * @param int $limit Number of top results to return
     * @param array<int>|null $allowedFileIds Optional array of business_knowledge_file_ids allowed for search
     * @return array Array of chunks
     */
    public function search(
        array $queryEmbedding,
        int $businessProfileId,
        int $limit = 3,
        ?array $allowedFileIds = null,
        ?string $provider = null,
        ?string $model = null,
        ?int $dimension = null
    ): array
    {
        $queryDimension = count($queryEmbedding);
        $guardEnabled = \App\Models\FeatureFlag::isEnabled('knowledge_dimension_guard', $businessProfileId);
        $this->lastSearchStats = [
            'business_id' => $businessProfileId,
            'candidate_count' => 0,
            'usable_count' => 0,
            'skipped_dimension_mismatch_count' => 0,
            'skipped_metadata_mismatch_count' => 0,
            'skipped_chunk_ids' => [],
        ];

        if (empty($queryEmbedding)) {
            return [];
        }

        // If explicit allowedFileIds array was passed and it is empty,
        // no knowledge files are accessible to this bot/channel context.
        if (is_array($allowedFileIds) && empty($allowedFileIds)) {
            return [];
        }

        // Fetch chunks for this tenant, optionally filtered by allowed file IDs
        $query = BusinessKnowledgeChunk::where('business_profile_id', $businessProfileId);

        if (is_array($allowedFileIds)) {
            $query->whereIn('business_knowledge_file_id', $allowedFileIds);
        }

        $chunks = $query->get();
        $this->lastSearchStats['candidate_count'] = $chunks->count();

        if ($chunks->isEmpty()) {
            return [];
        }

        $scoredChunks = [];

        foreach ($chunks as $chunk) {
            // $chunk->embedding is automatically cast to an array by Eloquent
            $dbEmbedding = $chunk->embedding;
            
            $dimensionMismatch = !is_array($dbEmbedding) || count($dbEmbedding) !== $queryDimension;
            $metadataMismatch = $guardEnabled && (
                (int) ($chunk->embedding_dim ?? 0) !== ($dimension ?? $queryDimension)
                || ($provider !== null && $chunk->embedding_provider !== $provider)
                || ($model !== null && $chunk->embedding_model !== $model)
            );

            if ($dimensionMismatch || $metadataMismatch) {
                if ($dimensionMismatch) {
                    $this->lastSearchStats['skipped_dimension_mismatch_count']++;
                }
                if ($metadataMismatch) {
                    $this->lastSearchStats['skipped_metadata_mismatch_count']++;
                }
                if (count($this->lastSearchStats['skipped_chunk_ids']) < 100) {
                    $this->lastSearchStats['skipped_chunk_ids'][] = (int) $chunk->id;
                }
                continue;
            }

            $similarity = $this->cosineSimilarity($queryEmbedding, $dbEmbedding);
            
            $scoredChunks[] = [
                'chunk' => $chunk,
                'score' => $similarity
            ];
        }

        // Sort by similarity score descending
        usort($scoredChunks, function ($a, $b) {
            return $b['score'] <=> $a['score'];
        });

        // Return top N chunks
        $this->lastSearchStats['usable_count'] = count($scoredChunks);
        if ($chunks->isNotEmpty() && $scoredChunks === [] && $this->lastSearchStats['skipped_dimension_mismatch_count'] > 0) {
            Log::warning('VectorSearchService: no usable knowledge chunks due to embedding mismatch', [
                'business_id' => $businessProfileId,
                'candidate_count' => $this->lastSearchStats['candidate_count'],
                'skipped_count' => $this->lastSearchStats['skipped_dimension_mismatch_count'] + $this->lastSearchStats['skipped_metadata_mismatch_count'],
                'query_dimension' => $queryDimension,
                'chunk_ids' => $this->lastSearchStats['skipped_chunk_ids'],
            ]);
        }

        $topChunks = array_slice($scoredChunks, 0, $limit);
        
        $result = [];
        foreach ($topChunks as $item) {
            $chunk = $item['chunk'];
            $chunk->setAttribute('similarity_score', (float) $item['score']);
            $result[] = $chunk;
        }

        return $result;
    }

    public function lastSearchStats(): array
    {
        return $this->lastSearchStats;
    }

    /**
     * Compute cosine similarity between two vectors
     * 
     * @param array<float> $vec1
     * @param array<float> $vec2
     * @return float
     */
    private function cosineSimilarity(array $vec1, array $vec2): float
    {
        $dotProduct = 0.0;
        $magnitude1 = 0.0;
        $magnitude2 = 0.0;

        $count = count($vec1);
        for ($i = 0; $i < $count; $i++) {
            $dotProduct += $vec1[$i] * $vec2[$i];
            $magnitude1 += $vec1[$i] * $vec1[$i];
            $magnitude2 += $vec2[$i] * $vec2[$i];
        }

        if ($magnitude1 == 0 || $magnitude2 == 0) {
            return 0.0;
        }

        return $dotProduct / (sqrt($magnitude1) * sqrt($magnitude2));
    }
}
