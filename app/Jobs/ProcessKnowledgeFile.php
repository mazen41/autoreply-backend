<?php

namespace App\Jobs;

use App\Models\BusinessKnowledgeFile;
use App\Models\BusinessKnowledgeChunk;
use App\Services\KnowledgeChunker;
use App\Services\EmbeddingsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class ProcessKnowledgeFile implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300; // 5 minutes max
    
    protected $fileId;

    /**
     * Create a new job instance.
     */
    public function __construct(int $fileId)
    {
        $this->fileId = $fileId;
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

            // Delete any existing chunks for this file to make this job idempotent
            BusinessKnowledgeChunk::where('business_knowledge_file_id', $file->id)->delete();

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

            // 2. Generate embeddings for chunks
            $embeddings = $embeddingsService->embedBatch($chunks);
            
            if (empty($embeddings) || count($embeddings) !== count($chunks)) {
                throw new \Exception("Failed to generate embeddings for all chunks");
            }

            // 3. Save chunks to DB
            DB::beginTransaction();
            
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
            
            // Insert in batches if large
            foreach (array_chunk($chunksData, 100) as $batch) {
                BusinessKnowledgeChunk::insert($batch);
            }
            
            DB::commit();

            $file->update(['status' => 'active', 'error_message' => null]);
            
            Log::info('ProcessKnowledgeFile: Successfully processed file', [
                'file_id' => $file->id,
                'chunks_count' => count($chunks)
            ]);

        } catch (\Exception $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            
            Log::error('ProcessKnowledgeFile: Failed to process file', [
                'file_id' => $this->fileId,
                'error' => $e->getMessage()
            ]);
            
            $file->update([
                'status' => 'failed',
                'error_message' => $e->getMessage()
            ]);
        }
    }
}
