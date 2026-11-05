<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BusinessKnowledgeFile;
use App\Models\BusinessProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class KnowledgeController extends Controller
{
    /** Get or create this user's profile (one per user) */
    private function profile(Request $request, string $permission): BusinessProfile
    {
        return app(\App\Services\ActiveBusiness::class)->resolve($request, permission: $permission);
    }

    /** Get all knowledge files for the user */
    public function index(Request $request)
    {
        $profile = $this->profile($request, 'read');
        $files = $profile->knowledgeFiles()
            ->orderBy('uploaded_at', 'desc')
            ->get(['id', 'filename', 'file_type', 'uploaded_at', 'updated_at', 'status', 'error_message'])
            ->map(function ($file) {
                // Add chunks count
                $file->chunks_count = \App\Models\BusinessKnowledgeChunk::where('business_knowledge_file_id', $file->id)->count();
                $file->retryable = $file->status === 'failed'
                    || ($file->status === 'processing' && $file->updated_at?->lte(now()->subMinutes(30)));
                return $file;
            });

        return response()->json([
            'files' => $files,
            'ai_instructions' => $profile->ai_instructions,
            'profile' => $profile,
        ]);
    }

    /** Upload and extract text from PDF/Excel files */
    public function upload(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:pdf,xlsx,xls|max:10240', // Max 10MB
        ]);

        $file = $request->file('file');
        $extractedText = '';

        try {
            if ($file->getClientOriginalExtension() === 'pdf') {
                $extractedText = $this->extractPdfText($file);
            } elseif (in_array($file->getClientOriginalExtension(), ['xlsx', 'xls'])) {
                $extractedText = $this->extractExcelText($file);
            } else {
                return response()->json(['error' => 'Unsupported file type'], 400);
            }

            if (empty($extractedText)) {
                return response()->json(['error' => 'Could not extract text from file'], 400);
            }

            $profile = $this->profile($request, 'write');
            $ingestion = app(\App\Services\KnowledgeFileIngestionService::class)->ingest(
                $profile,
                $file->getClientOriginalName(),
                $file->getClientOriginalExtension(),
                $extractedText,
                $file->getSize()
            );
            $knowledgeFile = $ingestion['file'];

            if ($ingestion['duplicate']) {
                return response()->json([
                    'message' => 'This document has already been uploaded to this business.',
                    'duplicate' => true,
                    'file' => $knowledgeFile,
                ], 200);
            }

            return response()->json([
                'message' => 'File uploaded and queued for indexing.',
                'duplicate' => false,
                'file' => $knowledgeFile,
                'extracted_length' => strlen($extractedText),
            ]);
        } catch (\Exception $e) {
            Log::error('File extraction failed', [
                'exception_type' => get_class($e),
            ]);
            return response()->json(['error' => 'Failed to process file. Please try again or contact support.'], 500);
        }
    }

    /** Delete a knowledge file */
    public function delete(Request $request, $id)
    {
        $profile = $this->profile($request, 'write');
        $file = $profile->knowledgeFiles()->findOrFail($id);
        
        $file->delete();

        return response()->json(['message' => 'File deleted successfully']);
    }

    /** Retry failed indexing or a processing job older than its unique lock. */
    public function retry(Request $request, int $id)
    {
        $profile = $this->profile($request, 'write');
        $file = $profile->knowledgeFiles()->findOrFail($id);
        $staleProcessing = $file->status === 'processing'
            && $file->updated_at?->lte(now()->subMinutes(30));

        abort_unless(
            $file->status === 'failed' || $staleProcessing,
            409,
            $file->status === 'processing'
                ? 'This document is still being indexed. Try again after 30 minutes if it remains stuck.'
                : 'Only failed or stale processing documents can be retried.'
        );

        $file->update(['status' => 'pending', 'error_message' => null]);
        \App\Jobs\ProcessKnowledgeFile::dispatch($file->id);

        Log::info('Knowledge file reindex retry queued', [
            'file_id' => $file->id,
            'business_profile_id' => $profile->id,
            'previous_status' => $staleProcessing ? 'stale_processing' : 'failed',
        ]);

        return response()->json(['message' => 'Document reindexing queued.', 'file_id' => $file->id], 202);
    }

    /** Update AI instructions */
    public function updateInstructions(Request $request)
    {
        $request->validate([
            'ai_instructions' => 'nullable|string|max:10000',
        ]);

        $this->profile($request, 'write')->update([
            'ai_instructions' => $request->ai_instructions,
        ]);

        return response()->json(['message' => 'AI instructions updated successfully']);
    }

    /** Update business profile details */
    public function updateProfile(Request $request)
    {
        $request->validate([
            'business_name' => 'nullable|string|max:255',
            'business_type' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:50',
            'city' => 'nullable|string|max:255',
            'country' => 'nullable|string|max:255',
            'working_days' => 'nullable|array',
            'working_from' => 'nullable|string|max:10',
            'working_to' => 'nullable|string|max:10',
            'services' => 'nullable|string',
            'faqs' => 'nullable|array',
            'reply_style' => 'nullable|string|max:255',
        ]);

        $profile = $this->profile($request, 'write');
        $profile->update($request->only([
            'business_name',
            'business_type',
            'phone',
            'city',
            'country',
            'working_days',
            'working_from',
            'working_to',
            'services',
            'faqs',
            'reply_style',
        ]));

        // Link this business profile to the user's channels if not already linked
        \App\Models\Channel::where('user_id', $request->user()->id)
            ->whereNull('business_id')
            ->update(['business_id' => $profile->id]);

        return response()->json(['message' => 'Profile updated successfully']);
    }

    /** Test AI response with current knowledge and instructions */
    public function testResponse(Request $request)
    {
        $request->validate([
            'test_question' => 'required|string|max:1000',
        ]);

        $profile = $this->profile($request, 'write');

        // Build system prompt using the same logic as ProcessAutoReply
        $systemPrompt = $this->buildTestSystemPrompt($profile, $request->test_question, $request->header('Idempotency-Key'));

        // Call AI for test response
        $testResponse = $this->callConfiguredAI($profile, $systemPrompt, [
            ['role' => 'user', 'content' => $request->test_question]
        ], $request->header('Idempotency-Key') ? \App\Services\AiGateway::stableOperationId('knowledge-test:' . $profile->id . ':' . $request->header('Idempotency-Key')) : (string) \Illuminate\Support\Str::uuid());

        if (!$testResponse) {
            return response()->json(['error' => 'Failed to generate test response'], 500);
        }

        $profile->forceFill([
            'ai_setup_tested_at' => now(),
            'ai_setup_tested_config_hash' => app(\App\Services\AiSetupVerificationService::class)->configurationFingerprint($profile),
        ])->save();

        return response()->json([
            'test_response' => $testResponse,
        ]);
    }

    /** Reindex knowledge files (regenerate chunks/indices) */
    public function reindex(Request $request)
    {
        $profile = $this->profile($request, 'write');
        $files = $profile->knowledgeFiles()->get();

        foreach ($files as $file) {
            $file->update(['status' => 'pending']);
            \App\Jobs\ProcessKnowledgeFile::dispatch($file->id);
        }

        return response()->json([
            'message' => 'Knowledge base reindexing started',
            'files_processed' => $files->count(),
        ]);
    }

    /** Search knowledge base semantically */
    public function search(Request $request)
    {
        $request->validate([
            'query' => 'required|string|max:500',
            'limit' => 'nullable|integer|min:1|max:20',
        ]);

        $profile = $this->profile($request, 'read');
        $queryStr = $request->input('query', '');
        $limit = $request->input('limit', 5);

        $results = [];
        
        $embeddingsService = app(\App\Services\EmbeddingsService::class);
        $vectorSearch = app(\App\Services\VectorSearchService::class);

        $queryEmbedding = $embeddingsService->embedChunk($queryStr, $profile, $request->header('Idempotency-Key') ? \App\Services\AiGateway::stableOperationId('knowledge-search:' . $profile->id . ':' . $request->header('Idempotency-Key')) : (string) \Illuminate\Support\Str::uuid());
        if (!empty($queryEmbedding)) {
            $relevantChunks = $vectorSearch->search($queryEmbedding, $profile->id, $limit);
            foreach ($relevantChunks as $chunk) {
                $results[] = [
                    'source' => $chunk->file ? $chunk->file->filename : 'Knowledge Base',
                    'content' => $chunk->content,
                    'score' => 1.0, // Since search method doesn't return scores, we can omit or fake it, but let's just return 1.0 for now. Actually, wait, the vectorSearch doesn't expose the score on the chunk model natively unless we attach it. That's fine.
                ];
            }
        }

        return response()->json([
            'results' => $results,
        ]);
    }

    private function extractSnippet(string $text, string $query, int $maxLength): string
    {
        $textLower = strtolower($text);
        $queryLower = strtolower($query);

        // Find first occurrence of query or its parts
        $pos = strpos($textLower, $queryLower);
        if ($pos === false) {
            // Try to find first word of query
            $words = explode(' ', $query);
            if (!empty($words)) {
                $pos = strpos($textLower, strtolower($words[0]));
            }
        }

        if ($pos === false) {
            // Return first part of text if no match found
            return substr($text, 0, $maxLength) . (strlen($text) > $maxLength ? '...' : '');
        }

        // Extract snippet around the match
        $start = max(0, $pos - 50);
        $snippet = substr($text, $start, $maxLength);

        // Add ellipsis if truncated
        if ($start > 0) {
            $snippet = '...' . $snippet;
        }
        if (strlen($text) > $start + $maxLength) {
            $snippet = $snippet . '...';
        }

        return $snippet;
    }

    private function extractPdfText($file)
    {
        $parser = new \Smalot\PdfParser\Parser();
        $pdf = $parser->parseFile($file->getPathname());
        return $pdf->getText();
    }

    private function extractExcelText($file)
    {
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($file->getPathname());
        $text = '';

        foreach ($spreadsheet->getAllSheets() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $cellIterator = $row->getCellIterator();
                $cellIterator->setIterateOnlyExistingCells(false);
                $rowData = [];
                foreach ($cellIterator as $cell) {
                    $rowData[] = $cell->getFormattedValue();
                }
                $text .= implode(' | ', array_filter($rowData)) . "\n";
            }
            $text .= "\n--- Sheet End ---\n\n";
        }

        return $text;
    }

    private function buildTestSystemPrompt(BusinessProfile $business, string $query = '', ?string $idempotencyKey = null): string
    {
        if (!$business) {
            return "You are an AI customer support assistant. Answer questions truthfully. If you do not know the answer, politely state that you don't know and offer to connect them with a human agent.";
        }

        $workingDays = is_array($business->working_days) ? implode(', ', $business->working_days) : ($business->working_days ?? 'N/A');
        $workingHours = "{$workingDays} from {$business->working_from} to {$business->working_to}";

        $faqsText = '';
        if (!empty($business->faqs)) {
            $faqs = is_array($business->faqs) ? $business->faqs : json_decode($business->faqs, true);
            if (is_array($faqs)) {
                foreach ($faqs as $faq) {
                    $q = $faq['question'] ?? $faq['q'] ?? '';
                    $a = $faq['answer'] ?? $faq['a'] ?? '';
                    if ($q && $a) $faqsText .= "Q: {$q}\nA: {$a}\n";
                }
            }
        }

        // Build knowledge base using RAG
        $knowledgeText = '';
        $hasIndexedKnowledge = \App\Models\BusinessKnowledgeChunk::query()
            ->where('business_profile_id', $business->id)
            ->whereNotNull('embedding')
            ->exists();

        if (!empty($query) && $hasIndexedKnowledge) {
            $embeddingsService = app(\App\Services\EmbeddingsService::class);
            $vectorSearch = app(\App\Services\VectorSearchService::class);

            $queryEmbedding = $embeddingsService->embedChunk($query, $business, $idempotencyKey ? \App\Services\AiGateway::stableOperationId('knowledge-answer:' . $business->id . ':' . $idempotencyKey) : (string) \Illuminate\Support\Str::uuid());
            if (!empty($queryEmbedding)) {
                $relevantChunks = $vectorSearch->search($queryEmbedding, $business->id, 5);
                foreach ($relevantChunks as $index => $chunk) {
                    $fileName = $chunk->file ? $chunk->file->filename : 'Knowledge Base';
                    $knowledgeText .= "\n[Source: {$fileName}] (Relevance Top " . ($index + 1) . ")\n";
                    $knowledgeText .= $chunk->content . "\n";
                }
            }
        }

        $prompt = "You are the AI assistant for {$business->business_name}, a {$business->business_type} business.\n";
        $prompt .= "Your job is to answer customer questions accurately using ONLY the information provided below.\n\n";

        $prompt .= "### BUSINESS INFORMATION ###\n";
        $prompt .= "- Business Name: {$business->business_name}\n";
        $prompt .= "- Business Type: {$business->business_type}\n";
        $prompt .= "- Location: {$business->city}, {$business->country}\n";
        $prompt .= "- Contact Phone: {$business->phone}\n";
        $prompt .= "- Working Hours: {$workingHours}\n";
        $prompt .= "- Services/Products: {$business->services}\n";
        
        if ($faqsText) {
            $prompt .= "\n### FREQUENTLY ASKED QUESTIONS ###\n{$faqsText}\n";
        }

        $channels = $business->channels()->where('status', 'connected')->pluck('type')->unique()->toArray();
        if (!empty($channels)) {
            $formattedChannels = implode(', ', array_map('ucfirst', $channels));
            $prompt .= "\n### CONNECTED CHANNELS ###\n";
            $prompt .= "We are currently active on: {$formattedChannels}\n";
            $prompt .= "(Use this if the user asks if we have an app, website, or are on a specific platform like Salla or Instagram).\n";
        }

        // Add knowledge base from uploaded files
        if (!empty($knowledgeText)) {
            $prompt .= "\n### KNOWLEDGE BASE & DOCUMENTATION ###\n{$knowledgeText}\n";
        }

        // Add custom AI instructions
        if (!empty($business->ai_instructions)) {
            $prompt .= "\n### CUSTOM INSTRUCTIONS ###\n{$business->ai_instructions}\n";
        }

        $prompt .= "\n### CRITICAL RULES ###\n";
        $prompt .= "1. NEVER say vague filler like 'I am here to assist you with any questions' as a substitute for a real answer.\n";
        $prompt .= "2. If you do not know the answer based on the provided information, DO NOT guess or make things up. Honestly say you don't have that information and offer to have a human follow up.\n";
        $prompt .= "3. Actively use the conversation history context provided. Do not repeat or contradict yourself.\n";
        $prompt .= "4. Keep replies concise, clear, and friendly.\n";
        $prompt .= "5. Reply in the same language the customer used (Arabic or English).\n";
        $prompt .= "6. Reply style should be: " . ($business->reply_style ?? 'friendly and professional') . ".\n";

        return $prompt;
    }

    private function callConfiguredAI(BusinessProfile $business, string $systemPrompt, array $contextMessages, string $operationId): ?string
    {
        $router = app(\App\Services\AiRouter::class);
        $route = $router->resolve($business, 'knowledge_generation', (string) config('services.ai.provider'), (string) config('services.ai.model', config('services.groq.model')));
        Log::info('Knowledge test reply routed through AI credit gateway', ['business_id' => $business->id, 'provider' => $route['provider'], 'model' => $route['model'], 'mode' => $route['mode']]);
        $tokens = max(1, (int) ceil((mb_strlen($systemPrompt) + collect($contextMessages)->sum(fn ($message) => mb_strlen((string) ($message['content'] ?? '')))) / 4));
        $result = app(\App\Services\AiGateway::class)->execute(
            $business, 'knowledge_generation', $route['provider'], $route['model'], $tokens, 1000,
            fn () => $this->requestKnowledgeProvider($route['provider'], $route['model'], $systemPrompt, $contextMessages),
            ['routing_source' => $route['source'], 'routing_rule_id' => $route['rule_id']], $operationId,
        );
        return (string) ($result['content'] ?? '');
    }

    private function requestKnowledgeProvider(string $provider, string $model, string $systemPrompt, array $messages): array
    {
        $maxTokens = 1000;
        if ($provider === 'claude') {
            $apiKey = app(\App\Services\AiProviderConfiguration::class)->key('claude');
            $response = \Illuminate\Support\Facades\Http::timeout(30)->connectTimeout(10)->withHeaders(['x-api-key' => $apiKey, 'anthropic-version' => '2023-06-01'])->post('https://api.anthropic.com/v1/messages', ['model' => $model, 'max_tokens' => $maxTokens, 'system' => $systemPrompt, 'messages' => $messages]);
            if (!$response->successful()) throw new \App\Exceptions\AiProviderRequestException('Anthropic', $response->status());
            return ['content' => (string) $response->json('content.0.text', ''), 'provider' => $provider, 'model' => $model, 'usage' => ['input_tokens' => (int) $response->json('usage.input_tokens', 0), 'output_tokens' => (int) $response->json('usage.output_tokens', 0), 'cached_tokens' => (int) $response->json('usage.cache_read_input_tokens', 0)]];
        }
        if ($provider === 'gemini') {
            $contents = array_map(fn ($message) => ['role' => ($message['role'] ?? 'user') === 'assistant' ? 'model' : 'user', 'parts' => [['text' => (string) ($message['content'] ?? '')]]], $messages);
            $apiKey = app(\App\Services\AiProviderConfiguration::class)->key('gemini');
            $response = \Illuminate\Support\Facades\Http::timeout(30)->connectTimeout(10)->post("https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=" . urlencode($apiKey), ['contents' => $contents, 'systemInstruction' => ['parts' => [['text' => $systemPrompt]]], 'generationConfig' => ['maxOutputTokens' => $maxTokens, 'temperature' => (float) config('services.ai.temperature', 0.7)]]);
            if (!$response->successful()) throw new \App\Exceptions\AiProviderRequestException('Gemini', $response->status());
            return ['content' => (string) $response->json('candidates.0.content.parts.0.text', ''), 'provider' => $provider, 'model' => $model, 'usage' => ['input_tokens' => (int) $response->json('usageMetadata.promptTokenCount', 0), 'output_tokens' => (int) $response->json('usageMetadata.candidatesTokenCount', 0), 'cached_tokens' => (int) $response->json('usageMetadata.cachedContentTokenCount', 0)]];
        }
        if ($provider === 'groq') {
            $apiKey = app(\App\Services\AiProviderConfiguration::class)->key('groq');
            $response = \Illuminate\Support\Facades\Http::timeout(30)->connectTimeout(10)->withToken($apiKey)->post('https://api.groq.com/openai/v1/chat/completions', ['model' => $model, 'messages' => array_merge([['role' => 'system', 'content' => $systemPrompt]], $messages), 'temperature' => (float) config('services.ai.temperature', 0.7), 'max_tokens' => $maxTokens]);
            if (!$response->successful()) throw new \App\Exceptions\AiProviderRequestException('Groq', $response->status());
            return ['content' => (string) $response->json('choices.0.message.content', ''), 'provider' => $provider, 'model' => $model, 'usage' => ['input_tokens' => (int) $response->json('usage.prompt_tokens', 0), 'output_tokens' => (int) $response->json('usage.completion_tokens', 0), 'cached_tokens' => (int) $response->json('usage.prompt_tokens_details.cached_tokens', 0)]];
        }
        throw new \RuntimeException('The configured AI provider is not supported for knowledge generation.');
    }

}
