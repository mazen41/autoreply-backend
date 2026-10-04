<?php

namespace Tests\Feature;

use App\Jobs\ProcessAutoReply;
use App\Models\Bot;
use App\Models\BotKnowledgeAssignment;
use App\Models\BusinessKnowledgeChunk;
use App\Models\BusinessKnowledgeFile;
use App\Models\BusinessProfile;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Package;
use App\Models\User;
use App\Services\EmbeddingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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

    private const UNIQUE_A = 'XYZUNIQUE-SALES-PLAYBOOK-RETURN-POLICY-30-DAYS';
    private const UNIQUE_B = 'XYZUNIQUE-INTERNAL-HR-SALARY-CONFIDENTIAL';

    private function makeFixture(?Bot $bot = null, array $channelOverrides = []): array
    {
        Package::create([
            'name' => 'Free', 'name_ar' => 'مجاني',
            'price_monthly' => 0, 'price_yearly' => 0,
            'ai_replies_limit' => -1, 'is_active' => true,
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
        });
    }

    /**
     * Run the job and return the system prompt sent to the AI provider.
     */
    private function runAndCaptureSystemPrompt(array $fixture): string
    {
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

    public function test_no_bot_preserves_global_business_knowledge(): void
    {
        $fixture = $this->makeFixture();
        [$fileA, $fileB] = $this->seedKnowledgeFiles($fixture['business']->id);
        $this->fakeEmbeddings();

        // No bot on the conversation, no bots assigned to the channel.
        $prompt = $this->runAndCaptureSystemPrompt($fixture);

        $this->assertStringContainsString(self::UNIQUE_A, $prompt, 'Without a resolved bot, global business knowledge must still be available');
    }
}
