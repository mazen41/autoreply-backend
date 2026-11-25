<?php

namespace Tests\Feature;

use App\Jobs\ProcessKnowledgeFile;
use App\Models\Bot;
use App\Models\BotKnowledgeAssignment;
use App\Models\BusinessKnowledgeChunk;
use App\Models\BusinessKnowledgeFile;
use App\Models\BusinessProfile;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageCorrection;
use App\Models\User;
use App\Services\VectorSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TrainingKnowledgeApprovalTest extends TestCase
{
    use RefreshDatabase;

    public function test_approval_deduplicates_file_and_assigns_it_only_to_the_originating_bot_and_channel(): void
    {
        Queue::fake();
        [$user, $business, $channel, $bot, $conversation, $correction] = $this->makeCorrection(withBot: true);

        $this->approve($user, $business, $correction)->assertOk();
        $this->approve($user, $business, $correction)->assertOk();

        $this->assertSame(1, BusinessKnowledgeFile::where('business_profile_id', $business->id)->count());
        $file = BusinessKnowledgeFile::query()->where('business_profile_id', $business->id)->firstOrFail();
        $this->assertStringNotContainsString('reviewer@example.com', $file->extracted_text);
        $this->assertStringNotContainsString('+966 50 530 8084', $file->extracted_text);
        $this->assertDatabaseHas('bot_knowledge_assignments', [
            'bot_id' => $bot->id,
            'business_knowledge_file_id' => $file->id,
            'channel_id' => $channel->id,
        ]);
        $this->assertSame(1, BotKnowledgeAssignment::where('business_knowledge_file_id', $file->id)->count());
        Queue::assertPushed(ProcessKnowledgeFile::class, 1);

        $chunk = BusinessKnowledgeChunk::create([
            'business_knowledge_file_id' => $file->id,
            'business_profile_id' => $business->id,
            'chunk_index' => 0,
            'content' => 'Redacted approved knowledge.',
            'embedding' => [1.0, 0.0],
        ]);
        $botAllowedFileIds = BotKnowledgeAssignment::query()
            ->where('bot_id', $bot->id)
            ->where('channel_id', $conversation->channel_id)
            ->pluck('business_knowledge_file_id')->all();
        $otherBot = Bot::create([
            'business_profile_id' => $business->id,
            'name' => 'Different bot',
            'status' => 'active',
        ]);
        $otherBotAllowedFileIds = BotKnowledgeAssignment::query()
            ->where('bot_id', $otherBot->id)
            ->where('channel_id', $conversation->channel_id)
            ->pluck('business_knowledge_file_id')->all();

        $search = app(VectorSearchService::class);
        $this->assertSame($chunk->id, $search->search([1.0, 0.0], $business->id, 5, $botAllowedFileIds)[0]->id);
        $this->assertSame([], $search->search([1.0, 0.0], $business->id, 5, $otherBotAllowedFileIds));
    }

    public function test_knowledge_approval_without_an_originating_bot_does_not_assign_the_file(): void
    {
        Queue::fake();
        [$user, $business, , , , $correction] = $this->makeCorrection(withBot: false);

        $this->approve($user, $business, $correction)->assertOk();

        $this->assertSame(1, BusinessKnowledgeFile::where('business_profile_id', $business->id)->count());
        $this->assertSame(0, BotKnowledgeAssignment::count());
    }

    private function makeCorrection(bool $withBot): array
    {
        $user = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $user->id]);
        $channel = Channel::factory()->create([
            'user_id' => $user->id,
            'business_id' => $business->id,
            'type' => 'facebook',
        ]);
        $bot = $withBot ? Bot::create([
            'business_profile_id' => $business->id,
            'name' => 'Origin bot',
            'status' => 'active',
        ]) : null;
        $conversation = Conversation::factory()->create([
            'business_id' => $business->id,
            'channel_id' => $channel->id,
            'bot_id' => $bot?->id,
        ]);
        $message = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'direction' => 'inbound',
            'content' => 'The customer wrote reviewer@example.com and +966 50 530 8084.',
        ]);
        $correction = MessageCorrection::create([
            'original_message_id' => $message->id,
            'ai_draft' => 'An answer.',
            'human_correction' => 'Contact reviewer@example.com or +966 50 530 8084.',
            'learning_type' => 'knowledge',
        ]);

        return [$user, $business, $channel, $bot, $conversation, $correction];
    }

    private function approve(User $user, BusinessProfile $business, MessageCorrection $correction)
    {
        return $this->actingAs($user, 'sanctum')
            ->withHeader('X-Business-Id', (string) $business->id)
            ->postJson("/api/training/corrections/{$correction->id}/approve");
    }
}
