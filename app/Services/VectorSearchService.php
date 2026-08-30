<?php

namespace App\Services;

use App\Models\BusinessKnowledgeChunk;
use Illuminate\Support\Facades\Log;

class VectorSearchService
{
    /**
     * Search for the most semantically relevant chunks given a query embedding
     *
     * @param array<float> $queryEmbedding The embedding of the search query
     * @param int $businessProfileId The ID of the business profile (tenant isolation)
     * @param int $limit Number of top results to return
     * @return array Array of chunks
     */
    public function search(array $queryEmbedding, int $businessProfileId, int $limit = 3): array
    {
        if (empty($queryEmbedding)) {
            return [];
        }

        // Fetch all chunks for this tenant
        // Since we are using standard MySQL and doing math in PHP,
        // this requires fetching all vectors for the tenant.
        // We limit to the active tenant to ensure this stays fast (typically < 1000 rows).
        $chunks = BusinessKnowledgeChunk::where('business_profile_id', $businessProfileId)->get();

        if ($chunks->isEmpty()) {
            return [];
        }

        $scoredChunks = [];

        foreach ($chunks as $chunk) {
            // $chunk->embedding is automatically cast to an array by Eloquent
            $dbEmbedding = $chunk->embedding;
            
            if (!is_array($dbEmbedding) || count($dbEmbedding) !== count($queryEmbedding)) {
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
        $topChunks = array_slice($scoredChunks, 0, $limit);
        
        $result = [];
        foreach ($topChunks as $item) {
            $result[] = $item['chunk'];
        }

        return $result;
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
