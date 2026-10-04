<?php

namespace Tests\Feature;

use App\Jobs\ProcessAutoReply;
use App\Models\Bot;
use App\Models\BusinessProfile;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Package;
use App\Models\User;
use App\Services\CustomerService;
use App\Services\EmbeddingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Phase 2 — AI prompt context.
 *
 * The system prompt must ground the AI in:
 *   • the channel/account it is speaking from (multi-account confusion guard)
 *   • the specific store whose live data is injected (when one is resolved)
 *   • the customer profile (name + tags ONLY — no internal identifiers)
 *
 * and must NOT expose internal fields (business ids, lead scores, platform
 * identities/ids).
 */
class AIPromptContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_prompt_includes_channel_account_customer_context_and_excludes_internal_fields(): void
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
        ]);

        // Bot assigned to a specific Salla store (ecommerce_channel_id) — the
        // store context must name THAT store, not another one.
        $store = Channel::create([
            'user_id' => $user->id,
            'business_id' => $business->id,
            'type' => 'salla',
            'page_id' => 'salla-store-508155',
            'page_name' => 'NazBiz Main Store',
            'status' => 'connected',
            'access_token' => 'SALLA-DUMMY',
            'ai_enabled' => true,
        ]);

        $bot = Bot::create([
            'business_profile_id' => $business->id,
            'name' => 'NazBiz Bot',
            'status' => 'active',
            'ai_provider' => 'groq',
            'ai_model' => 'llama-3.3-70b-versatile',
            'ai_confidence_threshold' => 0.70,
            'ecommerce_channel_id' => $store->id,
        ]);

        $channel = Channel::create([
            'user_id' => $user->id,
            'business_id' => $business->id,
            'type' => 'instagram',
            'page_id' => '17841400000000777',
            'page_name' => 'NazBiz Instagram Shop',
            'status' => 'connected',
            'access_token' => 'IG-DUMMY',
            'ai_enabled' => true,
        ]);

        $conversation = Conversation::create([
            'channel_id' => $channel->id,
            'business_id' => $business->id,
            'sender_id' => 'ig-sender-1001',
            'status' => 'open',
            'bot_id' => $bot->id,
        ]);

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'content' => 'Hello, what do you sell?',
            'direction' => 'inbound',
            'status' => 'received',
            'is_ai' => false,
            'send_status' => 'received',
        ]);

        // A known customer with a tag.
        $customer = app(CustomerService::class)->resolve(
            $business->id, 'instagram', 'ig-sender-1001', ['name' => 'Faisal']
        );
        $customer->update(['tags' => ['vip', 'repeat-buyer']]);
        $conversation->update(['customer_id' => $customer->id]);

        // Real knowledge pipeline is out of scope here — return no embedding so
        // the knowledge section is skipped deterministically.
        $this->app->instance(EmbeddingsService::class, new class {
            public function embedChunk(string $text): ?array
            {
                return null;
            }
        });

        $captured = null;
        config(['services.groq.api_key' => 'test-key']);
        Http::fake([
            'api.groq.com/openai/v1/chat/completions' => function ($request) use (&$captured) {
                $captured = $request;
                return Http::response([
                    'choices' => [[
                        'message' => ['content' => json_encode([
                            'reply' => 'Hi Faisal! We sell dresses.',
                            'intent' => 'greeting',
                            'needs_escalation' => false,
                            'needs_images' => false,
                            'confidence' => 0.95,
                            'escalation_reason' => 'none',
                        ])],
                    ]],
                ], 200);
            },
            'graph.facebook.com/*' => Http::response(['message_id' => 'mid_fake'], 200),
        ]);

        (new ProcessAutoReply($message->id))->handle();

        $this->assertNotNull($captured, 'AI provider must have been called');
        $body = $captured->data();
        $systemPrompt = (string) ($body['messages'][0]['content'] ?? '');

        // Channel & account context — present and specific.
        $this->assertStringContainsString('CHANNEL & ACCOUNT CONTEXT', $systemPrompt);
        $this->assertStringContainsString('Instagram', $systemPrompt);
        $this->assertStringContainsString('NazBiz Instagram Shop', $systemPrompt);

        // Store context — the resolved Salla store is named so live data cannot
        // be attributed to a different store.
        $this->assertStringContainsString('NazBiz Main Store', $systemPrompt);

        // Customer profile — name and tags, nothing else.
        $this->assertStringContainsString('CUSTOMER PROFILE', $systemPrompt);
        $this->assertStringContainsString('Faisal', $systemPrompt);
        $this->assertStringContainsString('vip', $systemPrompt);

        // Internal/secret fields must never reach the prompt. (No raw business
        // id check — small integers legitimately appear in prompt text.)
        $this->assertStringNotContainsString('business_profile_id', $systemPrompt);
        $this->assertStringNotContainsString('lead_score', $systemPrompt);
        $this->assertStringNotContainsString('platform_ids', $systemPrompt);
        $this->assertStringNotContainsString('ig-sender-1001', $systemPrompt);
    }

    public function test_prompt_states_unknown_customer_name_without_inventing_one(): void
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
        ]);

        $channel = Channel::create([
            'user_id' => $user->id,
            'business_id' => $business->id,
            'type' => 'whatsapp',
            'page_id' => 'wa-instance-9',
            'status' => 'connected',
            'access_token' => 'WA-DUMMY',
            'ai_enabled' => true,
        ]);

        $conversation = Conversation::create([
            'channel_id' => $channel->id,
            'business_id' => $business->id,
            'sender_id' => '966555000999',
            'status' => 'open',
            // sender_name deliberately absent — no customer name known
        ]);

        // Simulate what the WhatsApp webhook already did at inbound time:
        // a customer record exists but no name was ever learned.
        app(CustomerService::class)->attachToConversation($conversation);

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'content' => 'How fast is delivery to Jeddah?',
            'direction' => 'inbound',
            'status' => 'received',
            'is_ai' => false,
            'send_status' => 'received',
        ]);

        $this->app->instance(EmbeddingsService::class, new class {
            public function embedChunk(string $text): ?array
            {
                return null;
            }
        });

        $captured = null;
        config(['services.groq.api_key' => 'test-key']);
        Http::fake([
            'api.groq.com/openai/v1/chat/completions' => function ($request) use (&$captured) {
                $captured = $request;
                return Http::response([
                    'choices' => [[
                        'message' => ['content' => json_encode([
                            'reply' => 'Hi! Welcome to NazBiz.',
                            'intent' => 'greeting',
                            'needs_escalation' => false,
                            'needs_images' => false,
                            'confidence' => 0.95,
                            'escalation_reason' => 'none',
                        ])],
                    ]],
                ], 200);
            },
            // No bot resolved → default provider is gemini.
            'generativelanguage.googleapis.com/*generateContent*' => function ($request) use (&$captured) {
                $captured = $request;
                return Http::response([
                    'candidates' => [[
                        'content' => ['parts' => [['text' => json_encode([
                            'reply' => 'Hi! Welcome to NazBiz.',
                            'intent' => 'greeting',
                            'needs_escalation' => false,
                            'needs_images' => false,
                            'confidence' => 0.95,
                            'escalation_reason' => 'none',
                        ])]]],
                    ]],
                ], 200);
            },
            // WhatsApp reply dispatch (Evolution API).
            'localhost:8080/*' => Http::response(['key' => ['id' => 'MSG_FAKE']], 200),
        ]);

        (new ProcessAutoReply($message->id))->handle();

        $this->assertNotNull($captured);
        $body = $captured->data();
        // OpenAI-style (groq) vs Gemini payload shapes.
        $systemPrompt = (string) ($body['messages'][0]['content']
            ?? $body['systemInstruction']['parts'][0]['text']
            ?? '');

        $this->assertStringContainsString('CUSTOMER PROFILE', $systemPrompt);
        $this->assertStringContainsString('not provided yet', $systemPrompt);
    }
}
