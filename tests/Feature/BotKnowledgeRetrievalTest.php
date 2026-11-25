<?php

namespace Tests\Feature;

use App\Jobs\ProcessAutoReply;
use App\Models\Bot;
use App\Models\BotKnowledgeAssignment;
use App\Models\BusinessKnowledgeChunk;
use App\Models\BusinessKnowledgeFile;
use App\Models\BusinessProfile;
use App\Models\BusinessFeatureFlagAssignment;
use App\Models\AiProvider;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\FeatureFlag;
use App\Models\HandoffTask;
use App\Models\Message;
use App\Models\Package;
use App\Models\Subscription;
use App\Models\User;
use App\Services\EmbeddingsService;
use App\Services\AiGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Phase 2 — real per-bot knowledge retrieval.
 *
 * Contract enforced here:
 *   • A resolved bot receives ONLY the knowledge files explicitly assigned to
 *     it (BotKnowledgeAssignment), channel scope respected (channel_id NULL =
 *     all channels, channel_id = X = that channel only).
 *   • A bot with zero matching assignments gets NO knowledge — never a silent
 *     fallback to the unrestricted business knowledge base.
 *   • A conversation with NO resolved bot keeps the intentionally global
 *     business knowledge behaviour.
 *
 * Embeddings are faked via container binding (the job resolves
 * EmbeddingsService through app()); the real VectorSearchService scores real
 * seeded chunks, so the retrieval pipeline is exercised end-to-end.
 */
class BotKnowledgeRetrievalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['features.fail_closed_no_agent' => false]);
        Cache::flush();
    }

    private const UNIQUE_A = 'XYZUNIQUE-SALES-PLAYBOOK-RETURN-POLICY-30-DAYS';
    private const UNIQUE_B = 'XYZUNIQUE-INTERNAL-HR-SALARY-CONFIDENTIAL';

    public function test_knowledge_embedding_failsafe_flag_is_disabled_by_default(): void
    {
        $fixture = $this->makeFixture();

        $this->assertFalse(FeatureFlag::isEnabled('knowledge_embedding_failsafe', $fixture['business']->id));
    }

    public function test_relevance_shadow_flag_does_not_change_prompt_or_customer_reply(): void
    {
        $baseline = $this->makeFixture();
        [$baselineFile] = $this->seedKnowledgeFiles($baseline['business']->id);
        $baselineBot = $this->makeBot($baseline['business']->id);
        $baseline['conversation']->update(['bot_id' => $baselineBot->id]);
        BotKnowledgeAssignment::create([
            'bot_id' => $baselineBot->id,
            'business_knowledge_file_id' => $baselineFile->id,
            'channel_id' => null,
        ]);
        $this->fakeEmbeddings();
        $baselinePrompt = $this->runAndCaptureSystemPrompt($baseline);
        $baselineReply = Message::query()->where('conversation_id', $baseline['conversation']->id)
            ->where('direction', 'outbound')->latest('id')->value('content');

        $shadow = $this->makeFixture();
        [$shadowFile] = $this->seedKnowledgeFiles($shadow['business']->id);
        $shadowBot = $this->makeBot($shadow['business']->id);
        $shadow['conversation']->update(['bot_id' => $shadowBot->id]);
        BotKnowledgeAssignment::create([
            'bot_id' => $shadowBot->id,
            'business_knowledge_file_id' => $shadowFile->id,
            'channel_id' => null,
        ]);
        $this->enableKnowledgeRelevanceShadow($shadow['business']->id);
        $this->fakeEmbeddings();
        Log::spy();
        $shadowPrompt = $this->runAndCaptureSystemPrompt($shadow);
        $shadowReply = Message::query()->where('conversation_id', $shadow['conversation']->id)
            ->where('direction', 'outbound')->latest('id')->value('content');

        $this->assertSame($baselinePrompt, $shadowPrompt);
        $this->assertSame($baselineReply, $shadowReply);
        Log::shouldHaveReceived('info')->with('Knowledge relevance shadow sample', \Mockery::on(function (array $context) use ($shadow): bool {
            return $context['business_id'] === $shadow['business']->id
                && $context['top_score_bucket'] === 'at_least_075'
                && $context['top_chunk_count'] === 1
                && $context['would_filter_below_055'] === 0
                && !array_key_exists('message_id', $context)
                && !array_key_exists('customer_text', $context);
        }));
    }

    public function test_inactive_assigned_bot_fails_closed_and_marks_conversation_for_human(): void
    {
        $fixture = $this->makeFixture();
        $this->enableNoAgentFailClosed($fixture['business']->id);
        $inactiveBot = Bot::create([
            'business_profile_id' => $fixture['business']->id,
            'name' => 'Paused bot',
            'status' => 'inactive',
        ]);
        $inactiveBot->channels()->attach($fixture['channel']->id, ['is_primary' => true]);
        $fixture['conversation']->update(['bot_id' => $inactiveBot->id]);

        (new ProcessAutoReply($fixture['message']->id))->handle();

        $conversation = $fixture['conversation']->fresh();
        $this->assertTrue((bool) $conversation->requires_human);
        $this->assertFalse((bool) $conversation->ai_enabled);
        $this->assertSame('pending_human', $conversation->status);
        $this->assertDatabaseMissing('messages', [
            'conversation_id' => $conversation->id,
            'direction' => 'outbound',
            'is_ai' => true,
        ]);
        $this->assertDatabaseHas('handoff_tasks', [
            'conversation_id' => $conversation->id,
            'reason' => 'conversation_agent_inactive_or_invalid',
            'status' => 'open',
        ]);
    }

    public function test_channel_without_an_assigned_agent_fails_closed_when_feature_is_enabled(): void
    {
        Http::fake(['*' => Http::response([], 200)]);
        $fixture = $this->makeFixture();
        $this->enableNoAgentFailClosed($fixture['business']->id);

        (new ProcessAutoReply($fixture['message']->id))->handle();

        $conversation = $fixture['conversation']->fresh();
        $this->assertTrue((bool) $conversation->requires_human);
        $this->assertFalse((bool) $conversation->ai_enabled);
        $this->assertSame('pending_human', $conversation->status);
        $this->assertDatabaseHas('handoff_tasks', [
            'conversation_id' => $conversation->id,
            'reason' => 'no_bot_assigned',
            'status' => 'open',
        ]);
        $this->assertDatabaseMissing('messages', [
            'conversation_id' => $conversation->id,
            'direction' => 'outbound',
            'is_ai' => true,
        ]);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'chat/completions')
            || str_contains($request->url(), 'generateContent'));
    }

    public function test_multiple_active_agents_without_primary_fail_closed_and_create_one_handoff_task(): void
    {
        $fixture = $this->makeFixture();
        $first = $this->makeBot($fixture['business']->id);
        $second = $this->makeBot($fixture['business']->id);
        $fixture['channel']->bots()->attach($first->id, ['is_primary' => false]);
        $fixture['channel']->bots()->attach($second->id, ['is_primary' => false]);
        $this->enableNoAgentFailClosed($fixture['business']->id);
        Http::fake(['*' => Http::response([], 200)]);

        (new ProcessAutoReply($fixture['message']->id))->handle();
        (new ProcessAutoReply($fixture['message']->id))->handle();

        $this->assertSame(1, HandoffTask::where('conversation_id', $fixture['conversation']->id)->count());
        $this->assertDatabaseHas('handoff_tasks', [
            'conversation_id' => $fixture['conversation']->id,
            'reason' => 'channel_multiple_bots_no_primary',
            'status' => 'open',
        ]);
        $this->assertDatabaseMissing('messages', [
            'conversation_id' => $fixture['conversation']->id,
            'direction' => 'outbound',
            'is_ai' => true,
        ]);
    }

    private function enableNoAgentFailClosed(int $businessId): void
    {
        $flag = FeatureFlag::where('key', 'phase3_no_agent_fail_closed')->firstOrFail();
        BusinessFeatureFlagAssignment::create([
            'business_id' => $businessId,
            'feature_flag_id' => $flag->id,
            'enabled' => true,
        ]);
    }

    private function enableKnowledgeRelevanceShadow(int $businessId): void
    {
        $flag = FeatureFlag::where('key', 'knowledge_relevance_floor_shadow')->firstOrFail();
        BusinessFeatureFlagAssignment::create([
            'business_id' => $businessId,
            'feature_flag_id' => $flag->id,
            'enabled' => true,
        ]);
    }

    private function makeFixture(?Bot $bot = null, array $channelOverrides = []): array
    {
        Package::create([
            'name' => 'Knowledge Test Plan', 'name_ar' => 'باقة اختبار',
            'price_monthly' => 0, 'price_yearly' => 0,
            'ai_replies_limit' => -1, 'is_active' => true,
            'ai_credits_monthly' => 100000,
        ]);

        $user = User::forceCreate([
            'name' => 'Test', 'email' => 'test' . rand() . '@example.com',
            'password' => bcrypt('password'),
        ]);

        $business = BusinessProfile::create([
            'user_id' => $user->id,
            'business_name' => 'NazBiz',
            'knowledge_base' => 'We sell dresses.',
        ]);

        $package = Package::query()->where('name', 'Knowledge Test Plan')->firstOrFail();
        Subscription::create([
            'user_id' => $user->id,
            'business_id' => $business->id,
            'package_id' => $package->id,
            'status' => 'active',
            'billing_cycle' => 'monthly',
            'amount_paid' => 0,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
        ]);

        $channel = Channel::create(array_merge([
            'user_id' => $user->id,
            'business_id' => $business->id,
            'type' => 'instagram',
            'page_id' => '17841400000000001',
            'page_name' => 'NazBiz Main IG',
            'status' => 'connected',
            'access_token' => 'IG-DUMMY-TOKEN',
            'ai_enabled' => true,
        ], $channelOverrides));

        $conversation = Conversation::create([
            'channel_id' => $channel->id,
            'business_id' => $business->id,
            'sender_id' => 'ig-user-99',
            'status' => 'open',
            'bot_id' => $bot?->id,
        ]);

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'content' => 'What is your return policy?',
            'direction' => 'inbound',
            'status' => 'received',
            'is_ai' => false,
            'send_status' => 'received',
        ]);

        return compact('user', 'business', 'channel', 'conversation', 'message');
    }

    private function seedKnowledgeFiles(int $businessId): array
    {
        $fileA = BusinessKnowledgeFile::create([
            'business_profile_id' => $businessId,
            'filename' => 'sales-playbook.txt',
            'file_type' => 'txt',
            'extracted_text' => 'Sales playbook. ' . self::UNIQUE_A,
            'status' => 'processed',
        ]);

        $fileB = BusinessKnowledgeFile::create([
            'business_profile_id' => $businessId,
            'filename' => 'internal-hr.txt',
            'file_type' => 'txt',
            'extracted_text' => 'Internal HR. ' . self::UNIQUE_B,
            'status' => 'processed',
        ]);

        foreach ([$fileA, $fileB] as $index => $file) {
            BusinessKnowledgeChunk::create([
                'business_knowledge_file_id' => $file->id,
                'business_profile_id' => $businessId,
                'chunk_index' => 0,
                'content' => $file->extracted_text,
                'embedding' => [1.0, 0.0],
            ]);
        }

        return [$fileA, $fileB];
    }

    private function fakeEmbeddings(): void
    {
        $this->app->instance(EmbeddingsService::class, new class {
            public function embedChunk(string $text): ?array
            {
                return [1.0, 0.0];
            }

            public function activeProvider(): string { return 'gemini'; }
            public function activeModel(): string { return 'gemini-embedding-001'; }
            public function dimension(): int { return 768; }
        });
    }

    /**
     * Run the job and return the system prompt sent to the AI provider.
     */
    private function runAndCaptureSystemPrompt(array $fixture): string
    {
        config(['features.fail_closed_no_agent' => false]);
        $captured = null;

        Http::fake([
            'api.groq.com/openai/v1/chat/completions' => function ($request) use (&$captured) {
                $captured = $request;
                return Http::response([
                    'choices' => [[
                        'message' => ['content' => json_encode([
                            'reply' => 'Our return policy is 30 days.',
                            'intent' => 'question',
                            'needs_escalation' => false,
                            'needs_images' => false,
                            'confidence' => 0.95,
                            'escalation_reason' => 'none',
                        ])],
                    ]],
                ], 200);
            },
            // No-bot conversations default to the gemini provider.
            'generativelanguage.googleapis.com/*generateContent*' => function ($request) use (&$captured) {
                $captured = $request;
                return Http::response([
                    'candidates' => [[
                        'content' => ['parts' => [['text' => json_encode([
                            'reply' => 'Our return policy is 30 days.',
                            'intent' => 'question',
                            'needs_escalation' => false,
                            'needs_images' => false,
                            'confidence' => 0.95,
                            'escalation_reason' => 'none',
                        ])]]],
                    ]],
                ], 200);
            },
            'graph.facebook.com/*' => Http::response(['message_id' => 'mid_fake'], 200),
        ]);

        (new ProcessAutoReply($fixture['message']->id))->handle();

        $this->assertNotNull($captured, 'AI provider must have been called');
        $body = $captured->data();

        // OpenAI-style (groq) puts the system prompt in messages[0]; Gemini
        // puts it in systemInstruction.parts[0].text.
        return (string) ($body['messages'][0]['content']
            ?? $body['systemInstruction']['parts'][0]['text']
            ?? '');
    }

    private function makeBot(int $businessId): Bot
    {
        return Bot::create([
            'business_profile_id' => $businessId,
            'name' => 'Sales Bot',
            'status' => 'active',
            'ai_provider' => 'groq',
            'ai_model' => 'llama-3.3-70b-versatile',
            'ai_confidence_threshold' => 0.70,
        ]);
    }

    public function test_bot_receives_only_its_assigned_knowledge(): void
    {
        $fixture = $this->makeFixture();
        [$fileA, $fileB] = $this->seedKnowledgeFiles($fixture['business']->id);
        $this->fakeEmbeddings();

        $bot = $this->makeBot($fixture['business']->id);
        $fixture['conversation']->update(['bot_id' => $bot->id]);

        // Assigned: file A globally (channel_id NULL = every channel).
        BotKnowledgeAssignment::create([
            'bot_id' => $bot->id,
            'business_knowledge_file_id' => $fileA->id,
            'channel_id' => null,
        ]);

        $prompt = $this->runAndCaptureSystemPrompt($fixture);

        $this->assertStringContainsString(self::UNIQUE_A, $prompt, 'Bot must receive its assigned knowledge');
        $this->assertStringNotContainsString(self::UNIQUE_B, $prompt, 'Bot must NOT receive unassigned knowledge files');
    }

    public function test_bot_with_no_assignments_gets_no_unrestricted_fallback(): void
    {
        $fixture = $this->makeFixture();
        [$fileA, $fileB] = $this->seedKnowledgeFiles($fixture['business']->id);
        $this->fakeEmbeddings();

        // Bot resolved (conversation snapshot) but has NO knowledge assignments.
        $bot = $this->makeBot($fixture['business']->id);
        $fixture['conversation']->update(['bot_id' => $bot->id]);

        $prompt = $this->runAndCaptureSystemPrompt($fixture);

        $this->assertStringNotContainsString(self::UNIQUE_A, $prompt, 'A bot with zero assignments must not silently inherit business knowledge');
        $this->assertStringNotContainsString(self::UNIQUE_B, $prompt);
    }

    public function test_channel_scoped_assignment_does_not_leak_to_other_channels(): void
    {
        $fixture = $this->makeFixture();
        [$fileA, $fileB] = $this->seedKnowledgeFiles($fixture['business']->id);
        $this->fakeEmbeddings();

        $bot = $this->makeBot($fixture['business']->id);
        $fixture['conversation']->update(['bot_id' => $bot->id]);

        // Create a DIFFERENT channel and scope the assignment to it.
        $otherChannel = Channel::create([
            'user_id' => $fixture['user']->id,
            'business_id' => $fixture['business']->id,
            'type' => 'whatsapp',
            'page_id' => 'wa-other-1',
            'status' => 'connected',
            'access_token' => 'WA-DUMMY',
            'ai_enabled' => true,
        ]);

        BotKnowledgeAssignment::create([
            'bot_id' => $bot->id,
            'business_knowledge_file_id' => $fileA->id,
            'channel_id' => $otherChannel->id,
        ]);

        $prompt = $this->runAndCaptureSystemPrompt($fixture);

        $this->assertStringNotContainsString(self::UNIQUE_A, $prompt, 'Channel-scoped knowledge must not leak to other channels');
        $this->assertStringNotContainsString(self::UNIQUE_B, $prompt);
    }

    public function test_channel_scoped_assignment_applies_to_its_own_channel(): void
    {
        $fixture = $this->makeFixture();
        [$fileA, $fileB] = $this->seedKnowledgeFiles($fixture['business']->id);
        $this->fakeEmbeddings();

        $bot = $this->makeBot($fixture['business']->id);
        $fixture['conversation']->update(['bot_id' => $bot->id]);

        BotKnowledgeAssignment::create([
            'bot_id' => $bot->id,
            'business_knowledge_file_id' => $fileA->id,
            'channel_id' => $fixture['channel']->id,
        ]);

        $prompt = $this->runAndCaptureSystemPrompt($fixture);

        $this->assertStringContainsString(self::UNIQUE_A, $prompt, 'Channel-scoped knowledge must apply on its own channel');
        $this->assertStringNotContainsString(self::UNIQUE_B, $prompt);
    }

    public function test_no_bot_fails_closed_instead_of_running_ai_on_global_business_knowledge(): void
    {
        $fixture = $this->makeFixture();
        Http::fake(['*' => Http::response([], 200)]);

        // With the owner-enabled global fail-closed rollout, no bot means no
        // provider call; business knowledge remains inaccessible to an
        // unassigned conversation.
        (new ProcessAutoReply($fixture['message']->id))->handle();

        $this->assertDatabaseHas('handoff_tasks', [
            'conversation_id' => $fixture['conversation']->id,
            'reason' => 'no_bot_assigned',
            'status' => 'open',
        ]);
        $this->assertDatabaseMissing('messages', [
            'conversation_id' => $fixture['conversation']->id,
            'direction' => 'outbound',
            'is_ai' => true,
        ]);
        Http::assertNothingSent();
    }

    public function test_embedding_http_401_does_not_block_reply_when_failsafe_is_enabled(): void
    {
        $this->assertEmbeddingFailureStillReplies(401);
    }

    public function test_embedding_http_500_does_not_block_reply_when_failsafe_is_enabled(): void
    {
        $this->assertEmbeddingFailureStillReplies(500);
    }

    public function test_missing_embedding_key_is_reported_and_reply_continues(): void
    {
        $this->disableEmbeddingProvidersAndClearKeys();
        $embeddingService = new EmbeddingsService();
        $this->assertNull($embeddingService->embedChunk('What is your return policy?'));

        $this->activateProviderKey('groq', 'test-chat-key');
        $fixture = $this->makeFixture();
        $bot = $this->makeBot($fixture['business']->id);
        $fixture['conversation']->update(['bot_id' => $bot->id]);
        $this->enableKnowledgeEmbeddingFailsafe($fixture['business']->id);
        $this->app->instance(EmbeddingsService::class, $embeddingService);

        $this->mockAiGatewayOperations();
        $prompt = $this->fakeChatAndRun($fixture);

        $this->assertStringContainsString('Knowledge retrieval status: unavailable', $prompt);
        $this->assertDatabaseHas('messages', [
            'conversation_id' => $fixture['conversation']->id,
            'direction' => 'outbound',
            'is_ai' => true,
            'content' => 'Our return policy is 30 days.',
        ]);
    }

    private function assertEmbeddingFailureStillReplies(int $status): void
    {
        $this->disableEmbeddingProvidersAndClearKeys();
        $this->activateProviderKey('gemini', 'test-embedding-key');
        $this->activateProviderKey('groq', 'test-chat-key');

        $fixture = $this->makeFixture();
        $bot = $this->makeBot($fixture['business']->id);
        $fixture['conversation']->update(['bot_id' => $bot->id]);
        $this->enableKnowledgeEmbeddingFailsafe($fixture['business']->id);
        $this->mockAiGatewayOperations();
        Log::spy();

        $prompt = $this->fakeEmbeddingFailureAndChat($fixture, $status);

        $this->assertStringContainsString('Knowledge retrieval status: unavailable', $prompt);
        $this->assertSame(1, substr_count($prompt, 'Knowledge retrieval status: unavailable'));
        $this->assertDatabaseHas('messages', [
            'conversation_id' => $fixture['conversation']->id,
            'direction' => 'outbound',
            'is_ai' => true,
            'content' => 'Our return policy is 30 days.',
        ]);
        Log::shouldHaveReceived('warning')->with(
            'ProcessAutoReply: knowledge embedding unavailable',
            [
                'business_id' => $fixture['business']->id,
                'exception_type' => \App\Exceptions\AiProviderRequestException::class,
            ]
        )->once();
    }

    private function disableEmbeddingProvidersAndClearKeys(): void
    {
        config([
            'services.openai.api_key' => '',
            'services.claude.api_key' => '',
            'services.gemini.api_key' => '',
            'services.groq.api_key' => '',
        ]);

        foreach (AiProvider::query()->get() as $provider) {
            $provider->forceFill(['is_active' => false, 'credentials' => null])->save();
        }
    }

    private function activateProviderKey(string $slug, string $key): void
    {
        AiProvider::query()->where('slug', $slug)->firstOrFail()
            ->forceFill(['is_active' => true, 'credentials' => ['api_key' => $key]])
            ->save();
    }

    private function enableKnowledgeEmbeddingFailsafe(int $businessId): void
    {
        $flag = FeatureFlag::query()->where('key', 'knowledge_embedding_failsafe')->firstOrFail();
        BusinessFeatureFlagAssignment::create([
            'business_id' => $businessId,
            'feature_flag_id' => $flag->id,
            'enabled' => true,
        ]);
    }

    private function mockAiGatewayOperations(): void
    {
        $gateway = \Mockery::mock(AiGateway::class);
        $gateway->shouldReceive('execute')->andReturnUsing(
            fn (...$arguments) => ($arguments[6])()
        );
        $this->app->instance(AiGateway::class, $gateway);
    }

    private function fakeEmbeddingFailureAndChat(array $fixture, int $embeddingStatus): string
    {
        $captured = null;
        Http::fake(function ($request) use (&$captured, $embeddingStatus) {
            if (str_contains($request->url(), 'embedContent')) {
                return Http::response([], $embeddingStatus);
            }
            if (str_contains($request->url(), 'chat/completions')) {
                $captured = $request;
                return Http::response(['choices' => [[
                    'message' => ['content' => json_encode([
                        'reply' => 'Our return policy is 30 days.',
                        'intent' => 'question',
                        'needs_escalation' => false,
                        'needs_images' => false,
                        'confidence' => 0.95,
                        'escalation_reason' => 'none',
                    ])],
                ]]], 200);
            }
            return Http::response(['message_id' => 'mid_fake'], 200);
        });

        (new ProcessAutoReply($fixture['message']->id))->handle();
        $this->assertNotNull($captured, 'The customer reply provider must still be called.');
        return (string) data_get($captured->data(), 'messages.0.content', '');
    }

    private function fakeChatAndRun(array $fixture): string
    {
        $captured = null;
        Http::fake(function ($request) use (&$captured) {
            if (str_contains($request->url(), 'chat/completions')) {
                $captured = $request;
                return Http::response(['choices' => [[
                    'message' => ['content' => json_encode([
                        'reply' => 'Our return policy is 30 days.',
                        'intent' => 'question',
                        'needs_escalation' => false,
                        'needs_images' => false,
                        'confidence' => 0.95,
                        'escalation_reason' => 'none',
                    ])],
                ]]], 200);
            }
            return Http::response(['message_id' => 'mid_fake'], 200);
        });

        (new ProcessAutoReply($fixture['message']->id))->handle();
        $this->assertNotNull($captured, 'The customer reply provider must still be called.');
        return (string) data_get($captured->data(), 'messages.0.content', '');
    }
}
