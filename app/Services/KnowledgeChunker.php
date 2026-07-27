<?php

namespace App\Services;

class KnowledgeChunker
{
    /**
     * Chunk text into smaller pieces while preserving sentence boundaries
     * 
     * @param string $text The text to chunk
     * @param int $maxLength Maximum length per chunk (default: 2000)
     * @param int $overlap Overlap between chunks (default: 200)
     * @return array Array of text chunks
     */
    public static function chunkText(string $text, int $maxLength = 2000, int $overlap = 200): array
    {
        if (strlen($text) <= $maxLength) {
            return [$text];
        }

        $chunks = [];
        $sentences = self::splitIntoSentences($text);
        
        $currentChunk = '';
        $currentLength = 0;
        
        foreach ($sentences as $sentence) {
            $sentenceLength = strlen($sentence);
            
            // If adding this sentence would exceed max length, save current chunk
            if ($currentLength + $sentenceLength > $maxLength && $currentChunk !== '') {
                $chunks[] = trim($currentChunk);
                
                // Start new chunk with overlap from previous chunk
                if ($overlap > 0 && strlen($currentChunk) > $overlap) {
                    $overlapText = substr($currentChunk, -$overlap);
                    // Find a good sentence boundary in the overlap
                    $overlapSentences = self::splitIntoSentences($overlapText);
                    if (count($overlapSentences) > 1) {
                        // Keep the last complete sentence as overlap
                        $currentChunk = implode(' ', array_slice($overlapSentences, -1)) . ' ';
                        $currentLength = strlen($currentChunk);
                    } else {
                        $currentChunk = '';
                        $currentLength = 0;
                    }
                } else {
                    $currentChunk = '';
                    $currentLength = 0;
                }
            }
            
            $currentChunk .= $sentence . ' ';
            $currentLength += $sentenceLength + 1;
        }
        
        // Don't forget the last chunk
        if (trim($currentChunk) !== '') {
            $chunks[] = trim($currentChunk);
        }
        
        return $chunks;
    }

    /**
     * Split text into sentences while preserving sentence boundaries
     * 
     * @param string $text The text to split
     * @return array Array of sentences
     */
    private static function splitIntoSentences(string $text): array
    {
        // Normalize whitespace and line breaks
        $text = preg_replace('/\s+/', ' ', $text);
        
        // Split on common sentence terminators (. ! ?) followed by space or end
        // This is a simple regex - for production you might want a more sophisticated NLP approach
        $sentences = preg_split('/(?<=[.!?])\s+(?=[A-Z])/u', $text);
        
        // If regex didn't work well, fall back to character-based splitting
        if (count($sentences) === 1) {
            $sentences = [];
            $current = '';
            $length = strlen($text);
            
            for ($i = 0; $i < $length; $i++) {
                $char = $text[$i];
                $current .= $char;
                
                // Check for sentence endings
                if (in_array($char, ['.', '!', '?'])) {
                    // Look ahead to see if this is really a sentence end
                    $nextChar = $i + 1 < $length ? $text[$i + 1] : '';
                    if ($nextChar === ' ' || $nextChar === '' || $nextChar === "\n") {
                        $sentences[] = trim($current);
                        $current = '';
                    }
                }
            }
            
            if (trim($current) !== '') {
                $sentences[] = trim($current);
            }
        }
        
        return array_filter($sentences, function($sentence) {
            return trim($sentence) !== '';
        });
    }

    /**
     * Get the most relevant chunks for a query (simple keyword matching)
     * For production, consider using semantic search or embeddings
     * 
     * @param array $chunks Array of text chunks
     * @param string $query The search query
     * @param int $limit Number of chunks to return
     * @return array Most relevant chunks
     */
    public static function getRelevantChunks(array $chunks, string $query, int $limit = 3): array
    {
        if (empty($chunks)) {
            return [];
        }

        $queryWords = array_filter(explode(' ', strtolower($query)));
        $chunkScores = [];

        foreach ($chunks as $index => $chunk) {
            $chunkLower = strtolower($chunk);
            $score = 0;

            foreach ($queryWords as $word) {
                if (strlen($word) > 2) { // Ignore very short words
                    $occurrences = substr_count($chunkLower, $word);
                    $score += $occurrences * 10; // Each occurrence adds 10 points
                }
            }

            // Bonus for exact phrase match
            if (stripos($chunkLower, $query) !== false) {
                $score += 50;
            }

            $chunkScores[$index] = $score;
        }

        // Sort by score descending
        arsort($chunkScores);

        // Get top chunks
        $topIndices = array_slice(array_keys($chunkScores), 0, $limit);
        
        return array_map(function($index) use ($chunks) {
            return $chunks[$index];
        }, $topIndices);
    }

    /**
     * Format chunks for AI prompt with metadata
     * 
     * @param array $chunks Array of text chunks
     * @param string $sourceName Name of the source file
     * @return string Formatted string for AI prompt
     */
    public static function formatChunksForPrompt(array $chunks, string $sourceName = 'Knowledge Base'): string
    {
        if (empty($chunks)) {
            return '';
        }

        $output = "\n### {$sourceName} ###\n";
        
        foreach ($chunks as $index => $chunk) {
            $output .= "\n[Chunk {$index + 1}]\n{$chunk}\n";
        }
        
        return $output;
    }
}