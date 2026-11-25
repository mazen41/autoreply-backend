<?php

namespace Tests\Feature;

use App\Models\BusinessKnowledgeChunk;
use App\Models\BusinessKnowledgeFile;
use App\Models\BusinessProfile;
use App\Models\FeatureFlag;
use App\Models\User;
use App\Services\EmbeddingsService;
use App\Services\VectorSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Mockery;
use ReflectionMethod;
use ReflectionProperty;
use Tests\TestCase;

class KnowledgeEmbeddingMetadataTest extends TestCase
{
    use RefreshDatabase;

    public function test_vector_search_counts_dimension_mismatches_and_never_crosses_tenants(): void
    {
        [$business, $otherBusiness, $file] = $this->businessAndFilePair();
        $mismatched = BusinessKnowledgeChunk::create([
            'business_knowledge_file_id' => $file->id,
            'business_profile_id' => $business->id,
            'chunk_index' => 0,
            'content' => 'Local knowledge chunk.',
            'embedding' => array_fill(0, 768, 0.01),
            'embedding_provider' => 'gemini',
            'embedding_model' => 'gemini-embedding-001',
            'embedding_dim' => 768,
        ]);
        $otherFile = BusinessKnowledgeFile::create([
            'business_profile_id' => $otherBusiness->id,
            'filename' => 'other.txt',
            'file_type' => 'txt',
            'extracted_text' => 'Other tenant content.',
            'status' => 'active',
        ]);
        BusinessKnowledgeChunk::create([
            'business_knowledge_file_id' => $otherFile->id,
            'business_profile_id' => $otherBusiness->id,
            'chunk_index' => 0,
            'content' => 'Other tenant knowledge.',
            'embedding' => array_fill(0, 1536, 0.01),
            'embedding_provider' => 'openai',
            'embedding_model' => 'text-embedding-3-small',
            'embedding_dim' => 1536,
        ]);

        $search = app(VectorSearchService::class);
        $results = $search->search(array_fill(0, 1536, 0.01), $business->id, 5, null, 'openai', 'text-embedding-3-small', 1536);

        $this->assertSame([], $results);
        $this->assertSame(1, $search->lastSearchStats()['candidate_count']);
        $this->assertSame(1, $search->lastSearchStats()['skipped_dimension_mismatch_count']);
        $this->assertSame([$mismatched->id], $search->lastSearchStats()['skipped_chunk_ids']);
    }

    public function test_dimension_guard_skips_provider_model_mismatches_even_when_dimensions_match(): void
    {
        [$business, , $file] = $this->businessAndFilePair();
        $flag = FeatureFlag::query()->where('key', 'knowledge_dimension_guard')->firstOrFail();
        \DB::table('business_feature_flag_assignments')->insert([
            'business_id' => $business->id,
            'feature_flag_id' => $flag->id,
            'enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $chunk = BusinessKnowledgeChunk::create([
            'business_knowledge_file_id' => $file->id,
            'business_profile_id' => $business->id,
            'chunk_index' => 0,
            'content' => 'Provider-specific vector.',
            'embedding' => [1.0, 0.0],
            'embedding_provider' => 'gemini',
            'embedding_model' => 'old-model',
            'embedding_dim' => 2,
        ]);

        $search = app(VectorSearchService::class);
        $results = $search->search([1.0, 0.0], $business->id, 5, null, 'openai', 'text-embedding-3-small', 2);

        $this->assertSame([], $results);
        $this->assertSame(1, $search->lastSearchStats()['skipped_metadata_mismatch_count']);
        $this->assertSame([$chunk->id], $search->lastSearchStats()['skipped_chunk_ids']);
    }

    public function test_vector_search_attaches_similarity_score_to_each_returned_chunk(): void
    {
        [$business, , $file] = $this->businessAndFilePair();
        BusinessKnowledgeChunk::create([
            'business_knowledge_file_id' => $file->id,
            'business_profile_id' => $business->id,
            'chunk_index' => 0,
            'content' => 'Matching knowledge.',
            'embedding' => [1.0, 0.0],
        ]);

        $results = app(VectorSearchService::class)->search([1.0, 0.0], $business->id);

        $this->assertCount(1, $results);
        $this->assertSame(1.0, $results[0]->similarity_score);
    }

    public function test_reindex_converts_legacy_chunks_and_second_run_is_idempotent(): void
    {
        [$business, , $file] = $this->businessAndFilePair();
        $chunk = BusinessKnowledgeChunk::create([
            'business_knowledge_file_id' => $file->id,
            'business_profile_id' => $business->id,
            'chunk_index' => 0,
            'content' => 'Knowledge that needs a new vector.',
            'embedding' => array_fill(0, 768, 0.01),
            'embedding_provider' => 'gemini',
            'embedding_model' => 'gemini-embedding-001',
            'embedding_dim' => 768,
        ]);
        $embeddings = Mockery::mock(EmbeddingsService::class);
        $embeddings->shouldReceive('activeProvider')->andReturn('openai');
        $embeddings->shouldReceive('activeModel')->andReturn('text-embedding-3-small');
        $embeddings->shouldReceive('dimension')->andReturn(1536);
        $embeddings->shouldReceive('embedBatch')->once()->andReturn([array_fill(0, 1536, 0.02)]);
        $this->app->instance(EmbeddingsService::class, $embeddings);

        $this->artisan('knowledge:reindex', ['businessId' => $business->id])
            ->expectsOutput('Reindexed 1 chunks.')
            ->assertExitCode(0);
        $this->assertSame('openai', $chunk->fresh()->embedding_provider);
        $this->assertSame('text-embedding-3-small', $chunk->fresh()->embedding_model);
        $this->assertSame(1536, $chunk->fresh()->embedding_dim);
        $this->assertCount(1536, $chunk->fresh()->embedding);

        $embeddings->shouldNotReceive('embedBatch');
        $this->artisan('knowledge:reindex', ['businessId' => $business->id])
            ->expectsOutput('Reindexed 0 chunks.')
            ->assertExitCode(0);
    }

    public function test_dry_run_counts_mismatches_without_calling_embedding_provider(): void
    {
        [$business, , $file] = $this->businessAndFilePair();
        BusinessKnowledgeChunk::create([
            'business_knowledge_file_id' => $file->id,
            'business_profile_id' => $business->id,
            'chunk_index' => 0,
            'content' => 'Legacy vector.',
            'embedding' => [1.0],
        ]);
        $embeddings = Mockery::mock(EmbeddingsService::class);
        $embeddings->shouldReceive('activeProvider')->andReturn('openai');
        $embeddings->shouldReceive('activeModel')->andReturn('text-embedding-3-small');
        $embeddings->shouldReceive('dimension')->andReturn(1536);
        $embeddings->shouldNotReceive('embedBatch');
        $this->app->instance(EmbeddingsService::class, $embeddings);

        $this->artisan('knowledge:reindex', ['businessId' => $business->id, '--dry-run' => true])
            ->expectsOutput('Dry run: 1 chunks need reindexing.')
            ->assertExitCode(0);
        $this->assertNull(BusinessKnowledgeChunk::query()->firstOrFail()->embedding_provider);
    }

    public function test_openai_embedding_requests_pin_dimension_to_1536(): void
    {
        config(['services.openai.api_key' => 'local-test-key']);
        $service = new EmbeddingsService();
        Http::fake(['api.openai.com/v1/embeddings' => Http::response([
            'data' => [['embedding' => array_fill(0, 1536, 0.01)]],
        ])]);

        $method = new ReflectionMethod(EmbeddingsService::class, 'openaiEmbedSingle');
        $method->setAccessible(true);
        $method->invoke($service, 'test content', 2, 'text-embedding-3-small');

        Http::assertSent(fn ($request) => $request['dimensions'] === 1536);
        $this->assertSame('openai', $service->activeProvider());
        $this->assertSame('text-embedding-3-small', $service->activeModel());
        $this->assertSame(1536, $service->dimension());
    }

    public function test_shadow_report_aggregates_buckets_by_business_from_sanitized_log_counts(): void
    {
        $businessId = 947321;
        $path = storage_path('logs/laravel-shadow-report-test.log');
        $directory = dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }
        $entry = [
            'business_id' => $businessId,
            'top_score_bucket' => 'from_055_to_064',
            'top_chunk_count' => 5,
            'would_filter_below_055' => 1,
            'would_filter_below_065' => 3,
            'would_filter_below_075' => 4,
        ];
        file_put_contents($path, '[' . now()->format('Y-m-d H:i:s') . '] local.INFO: Knowledge relevance shadow sample ' . json_encode($entry) . PHP_EOL);

        try {
            $exitCode = Artisan::call('knowledge:shadow-report', ['--days' => 7]);
            $output = Artisan::output();
            $this->assertSame(0, $exitCode);
            $this->assertStringContainsString((string) $businessId, $output);
            $this->assertStringContainsString('from_055_to_064', $output);
            $this->assertStringContainsString('Would filter <0.75', $output);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    private function businessAndFilePair(): array
    {
        $user = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $user->id]);
        $otherBusiness = BusinessProfile::factory()->create(['user_id' => User::factory()->create()->id]);
        $file = BusinessKnowledgeFile::create([
            'business_profile_id' => $business->id,
            'filename' => 'knowledge.txt',
            'file_type' => 'txt',
            'extracted_text' => 'Fixture text.',
            'status' => 'active',
        ]);

        return [$business, $otherBusiness, $file];
    }
}
