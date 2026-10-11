<?php

namespace App\Jobs;

use App\Models\BusinessKnowledgeFile;
use App\Models\BusinessKnowledgeChunk;
use App\Services\KnowledgeChunker;
use App\Services\EmbeddingsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Throwable;

class ProcessKnowledgeFile implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;
    public int $tries = 3;
    public array $backoff = [30, 120];
    public int $uniqueFor = 1800;
    
    protected $fileId;

    /**
     * Create a new job instance.
     */
    public function __construct(int $fileId)
    {
        $this->fileId = $fileId;
    }

    public function uniqueId(): string
    {
        return 'knowledge-file:' . $this->fileId;
    }

    /**
     * Execute the job.
     */
    public function handle(EmbeddingsService $embeddingsService): void
    {
        $file = BusinessKnowledgeFile::find($this->fileId);
        
        if (!$file) {
            Log::error('ProcessKnowledgeFile: File not found', ['file_id' => $this->fileId]);
            return;
        }

        try {
            $file->update(['status' => 'processing']);

            $fullText = $file->extracted_text;
            
            if (empty(trim($fullText))) {
                $file->update([
                    'status' => 'failed',
                    'error_message' => 'File contains no text'
                ]);
                return;
            }

            // 1. Chunk the text
            $chunks = KnowledgeChunker::chunkText($fullText, 2000, 200);
            
            if (empty($chunks)) {
                $file->update([
                    'status' => 'failed',
                    'error_message' => 'Failed to extract any chunks from text'
                ]);
                return;
            }

            $chunkLimit = max(1, (int) config('knowledge.max_chunks_per_file', 500));
            if (count($chunks) > $chunkLimit) {
                throw new \RuntimeException("Knowledge file exceeds the {$chunkLimit} chunk limit.");
            }

            // 2. Generate embeddings for chunks
            $embeddings = $embeddingsService->embedBatch($chunks);
            
            if (empty($embeddings) || count($embeddings) !== count($chunks)) {
                throw new \Exception("Failed to generate embeddings for all chunks");
            }

            // 3. Save chunks to DB
            $chunksData = [];
            foreach ($chunks as $index => $chunkText) {
                $chunksData[] = [
                    'business_knowledge_file_id' => $file->id,
                    'business_profile_id' => $file->business_profile_id,
                    'chunk_index' => $index,
                    'content' => $chunkText,
                    'embedding' => json_encode($embeddings[$index]),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
            
            // Build the complete replacement before touching live chunks, then
            // atomically swap the indexed version.
            DB::transaction(function () use ($file, $chunksData): void {
                BusinessKnowledgeFile::query()->whereKey($file->id)->lockForUpdate()->firstOrFail();
                BusinessKnowledgeChunk::where('business_knowledge_file_id', $file->id)->delete();

                foreach (array_chunk($chunksData, 100) as $batch) {
                    BusinessKnowledgeChunk::insert($batch);
                }
            });

            $file->update(['status' => 'active', 'error_message' => null]);
            
            Log::info('ProcessKnowledgeFile: Successfully processed file', [
                'file_id' => $file->id,
                'chunks_count' => count($chunks)
            ]);

        } catch (Throwable $e) {
            Log::error('ProcessKnowledgeFile: Failed to process file', [
                'file_id' => $this->fileId,
                'error_class' => get_class($e),
            ]);

            $file->update([
                'status' => 'failed',
                'error_message' => 'Knowledge indexing failed; the last successfully indexed content is still available.',
            ]);
            throw $e;
        }
    }

    public function failed(Throwable $exception): void
    {
        $file = BusinessKnowledgeFile::find($this->fileId);
        if (!$file) {
            return;
        }

        $file->update([
            'status' => 'failed',
            'error_message' => 'Knowledge indexing failed after all retries; the last successfully indexed content is still available.',
        ]);

        Log::error('ProcessKnowledgeFile: retries exhausted', [
            'file_id' => $this->fileId,
            'error_class' => get_class($exception),
        ]);
    }
}
