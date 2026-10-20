<?php

namespace Tests\Feature;

use App\Jobs\ProcessKnowledgeFile;
use App\Models\BusinessKnowledgeChunk;
use App\Models\BusinessKnowledgeFile;
use App\Models\BusinessProfile;
use App\Models\User;
use App\Services\EmbeddingsService;
use App\Services\KnowledgeFileIngestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProcessKnowledgeFileTest extends TestCase
{
    use RefreshDatabase;

    public function test_business_owner_can_retry_a_failed_knowledge_file(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $user->id]);
        $file = BusinessKnowledgeFile::create([
            'business_profile_id' => $business->id,
            'filename' => 'catalog.pdf',
            'file_type' => 'pdf',
            'extracted_text' => 'Text to index again.',
            'status' => 'failed',
            'error_message' => 'Previous indexing failure.',
        ]);
        Sanctum::actingAs($user);

        $this->postJson("/api/knowledge/files/{$file->id}/retry", [], ['X-Business-Id' => (string) $business->id])
            ->assertAccepted()
            ->assertJsonPath('file_id', $file->id);

        $this->assertSame('pending', $file->fresh()->status);
        $this->assertNull($file->fresh()->error_message);
        Queue::assertPushed(ProcessKnowledgeFile::class);
    }

    public function test_business_owner_can_retry_knowledge_file_stuck_processing_for_over_thirty_minutes(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $user->id]);
        $file = BusinessKnowledgeFile::create([
            'business_profile_id' => $business->id,
            'filename' => 'stuck.pdf',
            'file_type' => 'pdf',
            'extracted_text' => 'Text to index again.',
            'status' => 'processing',
        ]);
        $file->forceFill(['updated_at' => now()->subMinutes(31)])->save();
        Sanctum::actingAs($user);

        $this->postJson("/api/knowledge/files/{$file->id}/retry", [], ['X-Business-Id' => (string) $business->id])
            ->assertAccepted();

        $this->assertSame('pending', $file->fresh()->status);
        Queue::assertPushed(ProcessKnowledgeFile::class);
    }

    public function test_knowledge_retry_rejects_fresh_jobs_and_files_from_another_business(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $user->id]);
        $otherBusiness = BusinessProfile::factory()->create(['user_id' => User::factory()->create()->id]);
        $freshFile = BusinessKnowledgeFile::create([
            'business_profile_id' => $business->id,
            'filename' => 'fresh.pdf',
            'file_type' => 'pdf',
            'extracted_text' => 'Still running.',
            'status' => 'processing',
        ]);
        $foreignFile = BusinessKnowledgeFile::create([
            'business_profile_id' => $otherBusiness->id,
            'filename' => 'private.pdf',
            'file_type' => 'pdf',
            'extracted_text' => 'Private.',
            'status' => 'failed',
        ]);
        Sanctum::actingAs($user);

        $this->postJson("/api/knowledge/files/{$freshFile->id}/retry", [], ['X-Business-Id' => (string) $business->id])
            ->assertStatus(409);
        $this->postJson("/api/knowledge/files/{$foreignFile->id}/retry", [], ['X-Business-Id' => (string) $business->id])
            ->assertNotFound();

        Queue::assertNothingPushed();
    }

    public function test_failed_reindex_keeps_the_previous_chunks_searchable(): void
    {
        $business = BusinessProfile::factory()->create(['user_id' => User::factory()->create()->id]);
        $file = BusinessKnowledgeFile::create([
            'business_profile_id' => $business->id,
            'filename' => 'catalog.pdf',
            'file_type' => 'pdf',
            'extracted_text' => 'Updated text that should fail to embed.',
            'status' => 'active',
        ]);
        $oldChunk = BusinessKnowledgeChunk::create([
            'business_knowledge_file_id' => $file->id,
            'business_profile_id' => $business->id,
            'chunk_index' => 0,
            'content' => 'Previously active catalog knowledge.',
            'embedding' => [1.0, 0.0],
        ]);
        $embeddings = \Mockery::mock(EmbeddingsService::class);
        $embeddings->shouldReceive('embedBatch')->once()->andReturn([]);

        app()->instance(EmbeddingsService::class, $embeddings);
        $job = new ProcessKnowledgeFile($file->id);
        try {
            $job->handle($embeddings);
            $this->fail('Embedding failure should reach the queue retry mechanism.');
        } catch (\Throwable $exception) {
            $job->failed($exception);
        }

        $this->assertDatabaseHas('business_knowledge_chunks', ['id' => $oldChunk->id]);
        $this->assertSame('Previously active catalog knowledge.', BusinessKnowledgeChunk::findOrFail($oldChunk->id)->content);
        $searchResults = app(\App\Services\VectorSearchService::class)->search([1.0, 0.0], $business->id);
        $this->assertSame($oldChunk->id, $searchResults[0]->id);
        $this->assertSame('failed', $file->fresh()->status);
        $this->assertStringContainsString('still available', $file->fresh()->error_message);
    }

    public function test_successful_reindex_swaps_chunks_atomically(): void
    {
        $business = BusinessProfile::factory()->create(['user_id' => User::factory()->create()->id]);
        $file = BusinessKnowledgeFile::create([
            'business_profile_id' => $business->id,
            'filename' => 'catalog.pdf',
            'file_type' => 'pdf',
            'extracted_text' => 'Current indexed catalog text.',
            'status' => 'active',
        ]);
        BusinessKnowledgeChunk::create([
            'business_knowledge_file_id' => $file->id,
            'business_profile_id' => $business->id,
            'chunk_index' => 0,
            'content' => 'Old text.',
            'embedding' => [1.0, 0.0],
        ]);
        $embeddings = \Mockery::mock(EmbeddingsService::class);
        $embeddings->shouldReceive('embedBatch')->once()->andReturn([[0.0, 1.0]]);

        (new ProcessKnowledgeFile($file->id))->handle($embeddings);

        $chunks = BusinessKnowledgeChunk::where('business_knowledge_file_id', $file->id)->get();
        $this->assertCount(1, $chunks);
        $this->assertSame('Current indexed catalog text.', $chunks[0]->content);
        $this->assertSame('active', $file->fresh()->status);
        $this->assertNull($file->fresh()->error_message);
    }

    public function test_duplicate_knowledge_content_is_reported_without_creating_another_file(): void
    {
        Queue::fake();
        $business = BusinessProfile::factory()->create(['user_id' => User::factory()->create()->id]);
        $service = app(KnowledgeFileIngestionService::class);

        $first = $service->ingest($business, 'policy.pdf', 'pdf', "  Return policy\nwithin 30 days.  ", 200);
        $second = $service->ingest($business, 'copy.pdf', 'pdf', 'Return policy within 30 days.', 200);

        $this->assertFalse($first['duplicate']);
        $this->assertTrue($second['duplicate']);
        $this->assertSame($first['file']->id, $second['file']->id);
        $this->assertSame(1, BusinessKnowledgeFile::where('business_profile_id', $business->id)->count());
        Queue::assertPushed(ProcessKnowledgeFile::class, 1);
    }

    public function test_chunk_limit_rejects_reindex_without_removing_existing_chunks(): void
    {
        config(['knowledge.max_chunks_per_file' => 1]);
        $business = BusinessProfile::factory()->create(['user_id' => User::factory()->create()->id]);
        $file = BusinessKnowledgeFile::create([
            'business_profile_id' => $business->id,
            'filename' => 'large.pdf',
            'file_type' => 'pdf',
            'extracted_text' => str_repeat('A', 1500) . '. ' . str_repeat('B', 1500) . '.',
            'status' => 'active',
        ]);
        $oldChunk = BusinessKnowledgeChunk::create([
            'business_knowledge_file_id' => $file->id,
            'business_profile_id' => $business->id,
            'chunk_index' => 0,
            'content' => 'Prior content.',
            'embedding' => [1.0, 0.0],
        ]);
        $embeddings = \Mockery::mock(EmbeddingsService::class);
        $embeddings->shouldNotReceive('embedBatch');
        $job = new ProcessKnowledgeFile($file->id);

        try {
            $job->handle($embeddings);
            $this->fail('Oversized chunk set should be rejected.');
        } catch (\Throwable $exception) {
            $job->failed($exception);
        }

        $this->assertDatabaseHas('business_knowledge_chunks', ['id' => $oldChunk->id]);
        $this->assertSame('failed', $file->fresh()->status);
    }
}
