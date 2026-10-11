<?php

namespace App\Services;

use App\Jobs\ProcessKnowledgeFile;
use App\Models\BusinessKnowledgeFile;
use App\Models\BusinessProfile;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class KnowledgeFileIngestionService
{
    /**
     * @return array{file: BusinessKnowledgeFile, duplicate: bool}
     */
    public function ingest(BusinessProfile $business, string $filename, string $fileType, string $text, ?int $fileSize = null): array
    {
        $normalizedText = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        $contentHash = hash('sha256', $normalizedText);

        $existing = $business->knowledgeFiles()->where('content_hash', $contentHash)->first();
        if ($existing) {
            return ['file' => $existing, 'duplicate' => true];
        }

        try {
            $file = DB::transaction(fn () => BusinessKnowledgeFile::create([
                'business_profile_id' => $business->id,
                'filename' => $filename,
                'file_type' => strtolower($fileType),
                'extracted_text' => $text,
                'status' => 'pending',
                'file_size' => $fileSize,
                'content_hash' => $contentHash,
            ]));
        } catch (QueryException $exception) {
            $existing = $business->knowledgeFiles()->where('content_hash', $contentHash)->first();
            if (!$existing) {
                throw $exception;
            }
            return ['file' => $existing, 'duplicate' => true];
        }

        ProcessKnowledgeFile::dispatch($file->id);

        return ['file' => $file, 'duplicate' => false];
    }
}
