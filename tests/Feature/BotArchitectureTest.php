<?php

namespace Tests\Feature;

use App\Models\Bot;
use App\Models\BotKnowledgeAssignment;
use App\Models\BusinessKnowledgeChunk;
use App\Models\BusinessKnowledgeFile;
use App\Models\BusinessProfile;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\User;
use App\Services\VectorSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BotArchitectureTest extends TestCase
{
    use RefreshDatabase;

    public function test_bot_knowledge_filtering_shared_vs_channel_specific()
    {
        $user = User::factory()->create();
        $business = BusinessProfile::create([
            'user_id' => $user->id,
            'name' => 'Tech Store',
            'business_name' => 'Tech Store',
        ]);

        $channelA = Channel::create([
            'user_id' => $user->id,
            'business_id' => $business->id,
            'type' => 'facebook',
            'page_id' => 'fb_page_egypt',
            'page_name' => 'Egypt Page',
            'access_token' => 'token_a',
            'status' => 'connected',
            'ai_enabled' => true,
        ]);

        $channelB = Channel::create([
            'user_id' => $user->id,
            'business_id' => $business->id,
            'type' => 'facebook',
            'page_id' => 'fb_page_saudi',
            'page_name' => 'Saudi Page',
            'access_token' => 'token_b',
            'status' => 'connected',
            'ai_enabled' => true,
        ]);

        $bot = Bot::create([
            'business_profile_id' => $business->id,
            'name' => 'Support Bot',
            'status' => 'active',
        ]);

        $bot->channels()->attach([$channelA->id, $channelB->id]);

        // File 1: Shared Knowledge (company FAQ)
        $sharedFile = BusinessKnowledgeFile::create([
            'business_profile_id' => $business->id,
            'filename' => 'company_faq.pdf',
            'file_type' => 'pdf',
            'extracted_text' => 'Global return policy is 14 days.',
            'status' => 'completed',
        ]);

        BusinessKnowledgeChunk::create([
            'business_knowledge_file_id' => $sharedFile->id,
            'business_profile_id' => $business->id,
            'chunk_index' => 0,
            'content' => 'Global return policy is 14 days.',
            'embedding' => [0.1, 0.2, 0.3],
        ]);

        // File 2: Egypt Channel Specific Knowledge
        $egyptFile = BusinessKnowledgeFile::create([
            'business_profile_id' => $business->id,
            'filename' => 'egypt_catalog.pdf',
            'file_type' => 'pdf',
            'extracted_text' => 'Egypt store price EGP 500.',
            'status' => 'completed',
        ]);

        BusinessKnowledgeChunk::create([
            'business_knowledge_file_id' => $egyptFile->id,
            'business_profile_id' => $business->id,
            'chunk_index' => 0,
            'content' => 'Egypt store price EGP 500.',
            'embedding' => [0.1, 0.2, 0.3],
        ]);

        // File 3: Saudi Channel Specific Knowledge
        $saudiFile = BusinessKnowledgeFile::create([
            'business_profile_id' => $business->id,
            'filename' => 'saudi_catalog.pdf',
            'file_type' => 'pdf',
            'extracted_text' => 'Saudi store price SAR 200.',
            'status' => 'completed',
        ]);

        BusinessKnowledgeChunk::create([
            'business_knowledge_file_id' => $saudiFile->id,
            'business_profile_id' => $business->id,
            'chunk_index' => 0,
            'content' => 'Saudi store price SAR 200.',
            'embedding' => [0.1, 0.2, 0.3],
        ]);

        // Attach assignments: shared File 1 (channel_id = NULL), Egypt File 2 (channel_id = A)
        BotKnowledgeAssignment::create([
            'bot_id' => $bot->id,
            'business_knowledge_file_id' => $sharedFile->id,
            'channel_id' => null, // Shared
        ]);

        BotKnowledgeAssignment::create([
            'bot_id' => $bot->id,
            'business_knowledge_file_id' => $egyptFile->id,
            'channel_id' => $channelA->id, // Egypt Channel Specific
        ]);

        BotKnowledgeAssignment::create([
            'bot_id' => $bot->id,
            'business_knowledge_file_id' => $saudiFile->id,
            'channel_id' => $channelB->id, // Saudi Channel Specific
        ]);

        // Test Egypt Channel A allowed file IDs for bot
        $egyptAllowed = BotKnowledgeAssignment::where('bot_id', $bot->id)
            ->where(function ($q) use ($channelA) {
                $q->whereNull('channel_id')->orWhere('channel_id', $channelA->id);
            })
            ->pluck('business_knowledge_file_id')
            ->toArray();

        $this->assertContains($sharedFile->id, $egyptAllowed);
        $this->assertContains($egyptFile->id, $egyptAllowed);
        $this->assertNotContains($saudiFile->id, $egyptAllowed);

        // Vector Search test on Egypt Channel
        $vectorSearch = new VectorSearchService();
        $results = $vectorSearch->search([0.1, 0.2, 0.3], $business->id, 5, $egyptAllowed);

        $fileIdsInResults = array_map(fn($c) => $c->business_knowledge_file_id, $results);
        $this->assertContains($sharedFile->id, $fileIdsInResults);
        $this->assertContains($egyptFile->id, $fileIdsInResults);
        $this->assertNotContains($saudiFile->id, $fileIdsInResults);
    }

    public function test_business_tenant_isolation_in_vector_search()
    {
        $user1 = User::factory()->create();
        $biz1 = BusinessProfile::create(['user_id' => $user1->id, 'name' => 'Biz 1']);

        $user2 = User::factory()->create();
        $biz2 = BusinessProfile::create(['user_id' => $user2->id, 'name' => 'Biz 2']);

        $file1 = BusinessKnowledgeFile::create([
            'business_profile_id' => $biz1->id,
            'filename' => 'biz1.pdf',
            'file_type' => 'pdf',
            'extracted_text' => 'Secret Biz 1 info',
            'status' => 'completed',
        ]);
        BusinessKnowledgeChunk::create([
            'business_knowledge_file_id' => $file1->id,
            'business_profile_id' => $biz1->id,
            'chunk_index' => 0,
            'content' => 'Secret Biz 1 info',
            'embedding' => [0.5, 0.5, 0.5],
        ]);

        $file2 = BusinessKnowledgeFile::create([
            'business_profile_id' => $biz2->id,
            'filename' => 'biz2.pdf',
            'file_type' => 'pdf',
            'extracted_text' => 'Secret Biz 2 info',
            'status' => 'completed',
        ]);
        BusinessKnowledgeChunk::create([
            'business_knowledge_file_id' => $file2->id,
            'business_profile_id' => $biz2->id,
            'chunk_index' => 0,
            'content' => 'Secret Biz 2 info',
            'embedding' => [0.5, 0.5, 0.5],
        ]);

        $vectorSearch = new VectorSearchService();
        $biz1Results = $vectorSearch->search([0.5, 0.5, 0.5], $biz1->id, 5);

        $this->assertCount(1, $biz1Results);
        $this->assertEquals($file1->id, $biz1Results[0]->business_knowledge_file_id);
    }

    public function test_legacy_unassigned_knowledge_fallback_when_no_bot_assigned()
    {
        $user = User::factory()->create();
        $business = BusinessProfile::create(['user_id' => $user->id, 'name' => 'Legacy Biz']);

        $legacyFile = BusinessKnowledgeFile::create([
            'business_profile_id' => $business->id,
            'filename' => 'legacy_info.pdf',
            'file_type' => 'pdf',
            'extracted_text' => 'Legacy business info',
            'status' => 'completed',
        ]);

        BusinessKnowledgeChunk::create([
            'business_knowledge_file_id' => $legacyFile->id,
            'business_profile_id' => $business->id,
            'chunk_index' => 0,
            'content' => 'Legacy business info',
            'embedding' => [0.2, 0.4, 0.6],
        ]);

        // When no bot is resolved ($allowedFileIds = null), vector search retrieves legacy files for tenant
        $vectorSearch = new VectorSearchService();
        $results = $vectorSearch->search([0.2, 0.4, 0.6], $business->id, 5, null);

        $this->assertCount(1, $results);
        $this->assertEquals($legacyFile->id, $results[0]->business_knowledge_file_id);
    }
}

