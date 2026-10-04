<?php

namespace Tests\Feature;

use App\Jobs\ProcessAutoReply;
use App\Models\Bot;
use App\Models\BusinessProfile;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Package;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Regression test for the 2026-10-02 production crash:
 *
 *   production.ERROR: Undefined variable $fieldStatus
 *   at app/Jobs/ProcessAutoReply.php:1193
 *
 * Root cause: the structured-state block added in c243fcd calls
 * determinePendingAction($checkoutState, $isPlaceOrder, $fieldStatus)
 * but $fieldStatus was never initialized in handle() before that point —
 * the only existing computation happened ~90 lines later, from the
 * post-merge $updatedCheckoutState. Every channel (Instagram included)
 * crashed with ErrorException before reaching the AI call.
 *
 * Fix: compute $fieldStatus from the same pre-merge $checkoutState that
 * determinePendingAction receives, via the existing
 * OrderCheckoutService::computeFieldStatus() helper.
 *
 * These tests run ProcessAutoReply::handle() end-to-end (webhook-persisted
 * message → bot resolution → field-status/structured-state → AI call →
 * response dispatch), so the crash line is executed on every path:
 *   - field-status NOT applicable (plain Instagram DM, null checkout_state)
 *   - field-status applicable (mid-checkout state, missing address)
 */
class ProcessAutoReplyFieldStatusTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Exact production scenario: Instagram DM, bot resolved from the
     * conversation snapshot (groq provider), no checkout state at all.
     * handle() must complete, reach the AI call, and dispatch the reply.
     */
    public function test_instagram_dm_without_checkout_state_reaches_ai_and_dispatches_reply(): void
    {
        $fixture = $this->makeFixture(null);

        $this->fakeAiAndMetaEndpoints(
            'Hi! Welcome to NazBiz — how can I help you today?'
        );

        // Regression: this threw "Undefined variable $fieldStatus" at the
        // structured-state block before the fix.
        (new ProcessAutoReply($fixture['message']->id))->handle();

        // AI was called through the bot's groq provider.
        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.groq.com/openai/v1/chat/completions'));

        // Reply was dispatched through the Instagram (Meta Graph) send path.
        Http::assertSent(fn ($request) => str_contains($request->url(), 'graph.facebook.com/v19.0/me/messages'));

        // AI reply persisted in the unified inbox.
        $reply = Message::where('conversation_id', $fixture['conversation']->id)
            ->where('direction', 'outbound')
            ->where('is_ai', true)
            ->latest('id')
            ->first();

        $this->assertNotNull($reply, 'AI reply must be persisted — job must survive the structured-state block');
        $this->assertSame('Hi! Welcome to NazBiz — how can I help you today?', $reply->content);
    }

    /**
     * Field-status feature APPLICABLE: mid-checkout conversation with a
     * selected product, name and phone collected, address still missing.
     * The structured-state block must see the pre-merge field status and
     * the job must still complete through AI + dispatch.
     */
    public function test_mid_checkout_instagram_message_reaches_ai_and_preserves_state(): void
    {
        $fixture = $this->makeFixture([
            'salla_product_id'    => '101',
            'sku'                 => 'ND-001',
            'product_name'        => 'Navy Dress',
            'product_price'       => 200,
            'product_currency'    => 'SAR',
            'full_name'           => 'Sara',
            'phone'               => '0555000111',
            'confirmation_state'  => 'collecting_info',
        ], 'I want to order');

        $this->fakeAiAndMetaEndpoints(
            'Could you share your delivery address please?'
        );

        (new ProcessAutoReply($fixture['message']->id))->handle();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.groq.com/openai/v1/chat/completions'));
        Http::assertSent(fn ($request) => str_contains($request->url(), 'graph.facebook.com/v19.0/me/messages'));

        $reply = Message::where('conversation_id', $fixture['conversation']->id)
            ->where('direction', 'outbound')
            ->where('is_ai', true)
            ->latest('id')
            ->first();

        $this->assertNotNull($reply, 'AI reply must be persisted for mid-checkout turn');

        // Previously collected checkout fields survive the turn.
        $state = $fixture['conversation']->fresh()->checkout_state;
        $this->assertSame('101', $state['salla_product_id'] ?? null);
        $this->assertSame('0555000111', $state['phone'] ?? null);
        $this->assertSame('Sara', $state['full_name'] ?? null);
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    private function makeFixture(?array $checkoutState, string $incomingText = 'Hello, what is the price of the navy dress?'): array
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

        $channel = Channel::create([
            'user_id' => $user->id,
            'business_id' => $business->id,
            'type' => 'instagram',
            'page_id' => '17841400000000000',
            'status' => 'connected',
            'access_token' => 'IG-DUMMY-TOKEN',
            'ai_enabled' => true,
        ]);

        // Bot resolved via Priority 1 (conversation snapshot) exactly as in
        // the production log: resolution_source=conversation_snapshot,
        // ai_provider=groq, ai_model=llama-3.3-70b-versatile.
        $bot = Bot::create([
            'business_profile_id' => $business->id,
            'name' => 'NazBiz Bot',
            'status' => 'active',
            'ai_provider' => 'groq',
            'ai_model' => 'llama-3.3-70b-versatile',
            // Bots table stores this as decimal(3,2) — 0-1 scale, matching the
            // AI's 0-1 confidence output. (BusinessProfile's integer/percent
            // default of 70 is a separate pre-existing scale mismatch.)
            'ai_confidence_threshold' => 0.70,
        ]);

        $conversation = Conversation::create([
            'channel_id' => $channel->id,
            'business_id' => $business->id,
            'sender_id' => 'ig-user-431122',
            'status' => 'open',
            'bot_id' => $bot->id,
            'checkout_state' => $checkoutState,
        ]);

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'content' => $incomingText,
            'direction' => 'inbound',
            'status' => 'received',
            'is_ai' => false,
            'send_status' => 'received',
        ]);

        return compact('user', 'business', 'channel', 'conversation', 'message', 'bot');
    }

    private function fakeAiAndMetaEndpoints(string $aiReplyText): void
    {
        config(['services.groq.api_key' => 'test-groq-key']);

        Http::fake([
            // Knowledge-base embedding lookup (returns empty → no vector search)
            'generativelanguage.googleapis.com/*embedContent*' => Http::response(
                ['embedding' => ['values' => []]], 200
            ),
            // Gemini fallback chat (in case groq path falls back)
            'generativelanguage.googleapis.com/*generateContent*' => Http::response([
                'candidates' => [[
                    'content' => ['parts' => [['text' => json_encode([
                        'reply' => $aiReplyText,
                        'intent' => 'question',
                        'needs_escalation' => false,
                        'needs_images' => false,
                        'confidence' => 0.9,
                        'escalation_reason' => 'none',
                    ])]]],
                ]],
            ], 200),
            // Groq (bot's configured provider — the production path)
            'api.groq.com/openai/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => ['content' => json_encode([
                        'reply' => $aiReplyText,
                        'intent' => 'question',
                        'needs_escalation' => false,
                        'needs_images' => false,
                        'confidence' => 0.9,
                        'escalation_reason' => 'none',
                    ])],
                ]],
            ], 200),
            // Instagram reply dispatch (shares the Meta Graph send path)
            'graph.facebook.com/*' => Http::response(
                ['recipient_id' => 'ig-user-431122', 'message_id' => 'mid_FAKE_1'], 200
            ),
        ]);
    }
}
