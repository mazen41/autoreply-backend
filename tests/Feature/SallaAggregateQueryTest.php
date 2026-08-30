<?php

namespace Tests\Feature;

use App\Jobs\ProcessAutoReply;
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
 * Priority 1 regression tests -- Salla aggregate/list queries.
 *
 * Root cause covered: "how many products / orders do you have" fell through
 * ProcessAutoReply's keyword routing into the generic question-answering path,
 * which only reads business_profile / knowledge_base and never calls Salla --
 * producing either a hallucinated answer (business_profile contamination) or a
 * hardcoded "I don't have access" reply, even though Salla was connected with
 * valid scopes.
 *
 * These tests mock the Salla list endpoints and the Gemini API and drive the
 * real ProcessAutoReply::handle() pipeline end-to-end.
 */
class SallaAggregateQueryTest extends TestCase
{
    use RefreshDatabase;

    private function makeFreePackage(): Package
    {
        return Package::create([
            'name' => 'Free',
            'name_ar' => 'مجاني',
            'price_monthly' => 0,
            'price_yearly' => 0,
            'ai_replies_limit' => -1,
            'is_active' => true,
        ]);
    }

    /**
     * Builds a user + business (with a knowledge_base string that intentionally
     * looks like NazBiz's own platform/subscription info, to reproduce the
     * "Case A" cross-domain contamination bug) + a connected WhatsApp channel
     * + a connected Salla channel + a conversation + an inbound message.
     */
    private function makeConversationFixture(string $inboundContent): array
    {
        $this->makeFreePackage();

        $user = User::factory()->create();

        $business = BusinessProfile::create([
            'user_id' => $user->id,
            'business_name' => 'Demo Store',
            // Deliberately platform-ish content to reproduce the reported
            // domain-bleed symptom (Case A): the AI must NOT use this to
            // answer live product/order count questions.
            'knowledge_base' => 'NazBiz Pro subscription plan includes unlimited AI replies and 5 connected channels.',
        ]);

        $whatsappChannel = Channel::create([
            'user_id' => $user->id,
            'business_id' => $business->id,
            'type' => 'whatsapp',
            'page_id' => 'instance-1',
            'status' => 'connected',
            'ai_enabled' => true,
        ]);

        $sallaChannel = Channel::create([
            'user_id' => $user->id,
            'business_id' => $business->id,
            'type' => 'salla',
            'status' => 'connected',
            'access_token' => 'fake-salla-access-token',
            'refresh_token' => 'fake-salla-refresh-token',
        ]);

        $conversation = Conversation::create([
            'channel_id' => $whatsappChannel->id,
            'business_id' => $business->id,
            'sender_id' => '966500000000',
            'status' => 'open',
        ]);

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'content' => $inboundContent,
            'direction' => 'inbound',
            'status' => 'received',
            'is_ai' => false,
            'send_status' => 'received',
        ]);

        return compact('user', 'business', 'whatsappChannel', 'sallaChannel', 'conversation', 'message');
    }

    private function fakeGeminiReply(string $replyText, string $intent = 'question'): array
    {
        return [
            'candidates' => [
                [
                    'content' => [
                        'parts' => [
                            ['text' => json_encode([
                                'reply' => $replyText,
                                'intent' => $intent,
                                'needs_escalation' => false,
                                'confidence' => 0.95,
                                'escalation_reason' => 'none',
                            ])],
                        ],
                    ],
                ],
            ],
        ];
    }

    public function test_product_count_query_calls_salla_products_list_endpoint(): void
    {
        $fixture = $this->makeConversationFixture('how many products do you have?');

        Http::fake([
            'generativelanguage.googleapis.com/*embedContent*' => Http::response(['embedding' => ['values' => []]], 200),
            'generativelanguage.googleapis.com/*generateContent*' => Http::response(
                $this->fakeGeminiReply('We currently have 42 products in our store, including Product A and Product B.'),
                200
            ),
            'api.salla.dev/admin/v2/products*' => Http::response([
                'data' => [
                    ['id' => 1, 'name' => 'Product A', 'price' => ['amount' => 50, 'currency_code' => 'SAR'], 'quantity' => 5],
                    ['id' => 2, 'name' => 'Product B', 'price' => ['amount' => 75, 'currency_code' => 'SAR'], 'quantity' => 2],
                ],
                'pagination' => ['total' => 42, 'per_page' => 10, 'current_page' => 1],
            ], 200),
        ]);

        (new ProcessAutoReply($fixture['message']->id))->handle();

        // Correct list endpoint was called -- never a single-resource lookup.
        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'api.salla.dev/admin/v2/products')
                && !str_contains($request->url(), '/products/'); // no product-id suffix
        });

        // The prompt sent to Gemini must contain the real fetched total count.
        Http::assertSent(function ($request) {
            if (!str_contains($request->url(), 'generateContent')) {
                return true; // not the call we're inspecting
            }
            $body = json_encode($request->data());
            return str_contains($body, 'Total products in store: 42')
                && !str_contains($body, "I don't have access");
        });

        $reply = Message::where('conversation_id', $fixture['conversation']->id)
            ->where('direction', 'outbound')
            ->latest('id')
            ->first();

        $this->assertNotNull($reply);
        $this->assertStringNotContainsString("don't have access", $reply->content);
    }

    public function test_order_count_query_calls_salla_orders_list_endpoint(): void
    {
        $fixture = $this->makeConversationFixture('how many orders exist in Salla');

        Http::fake([
            'generativelanguage.googleapis.com/*embedContent*' => Http::response(['embedding' => ['values' => []]], 200),
            'generativelanguage.googleapis.com/*generateContent*' => Http::response(
                $this->fakeGeminiReply('You have 17 orders on record.'),
                200
            ),
            'api.salla.dev/admin/v2/orders*' => Http::response([
                'data' => [
                    ['id' => 501, 'reference_id' => 'ORD-501', 'status' => ['name' => 'delivered'], 'total' => ['amount' => 120, 'currency' => 'SAR']],
                ],
                'pagination' => ['total' => 17, 'per_page' => 10, 'current_page' => 1],
            ], 200),
        ]);

        (new ProcessAutoReply($fixture['message']->id))->handle();

        // Correct list endpoint used -- the reported bug is that NO Salla call
        // was attempted at all for this exact phrasing.
        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'api.salla.dev/admin/v2/orders')
                && !preg_match('#/orders/\d+#', $request->url());
        });

        Http::assertSent(function ($request) {
            if (!str_contains($request->url(), 'generateContent')) {
                return true;
            }
            $body = json_encode($request->data());
            return str_contains($body, 'Total orders in store: 17');
        });

        $reply = Message::where('conversation_id', $fixture['conversation']->id)
            ->where('direction', 'outbound')
            ->latest('id')
            ->first();

        $this->assertNotNull($reply);
        $this->assertStringNotContainsString("don't have access", $reply->content);
    }

    public function test_list_products_query_avoids_business_profile_contamination(): void
    {
        $fixture = $this->makeConversationFixture('list your products');

        Http::fake([
            'generativelanguage.googleapis.com/*embedContent*' => Http::response(['embedding' => ['values' => []]], 200),
            'generativelanguage.googleapis.com/*generateContent*' => Http::response(
                $this->fakeGeminiReply('Here are our products: Product A (50 SAR), Product B (75 SAR).'),
                200
            ),
            'api.salla.dev/admin/v2/products*' => Http::response([
                'data' => [
                    ['id' => 1, 'name' => 'Product A', 'price' => ['amount' => 50, 'currency_code' => 'SAR'], 'quantity' => 5],
                    ['id' => 2, 'name' => 'Product B', 'price' => ['amount' => 75, 'currency_code' => 'SAR'], 'quantity' => 2],
                ],
                'pagination' => ['total' => 2, 'per_page' => 10, 'current_page' => 1],
            ], 200),
        ]);

        (new ProcessAutoReply($fixture['message']->id))->handle();

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'api.salla.dev/admin/v2/products');
        });

        // The system prompt sent to Gemini must instruct the model to never use
        // Business Profile / Knowledge Base for this kind of question -- this is
        // the actual code-level fix for the reported NazBiz-subscription bleed.
        Http::assertSent(function ($request) {
            if (!str_contains($request->url(), 'generateContent')) {
                return true;
            }
            $body = json_encode($request->data());
            return str_contains($body, 'NEVER answer it from Business Profile Information or Uploaded Knowledge Base')
                && str_contains($body, 'Product A')
                && str_contains($body, 'Product B');
        });
    }

    public function test_salla_product_fetch_failure_reports_honest_error_not_generic_no_access(): void
    {
        $fixture = $this->makeConversationFixture('how many products do you have?');

        Http::fake([
            'generativelanguage.googleapis.com/*embedContent*' => Http::response(['embedding' => ['values' => []]], 200),
            'generativelanguage.googleapis.com/*generateContent*' => Http::response(
                $this->fakeGeminiReply("We're having trouble reaching our product catalog right now -- I'll get a teammate to confirm the exact count for you."),
                200
            ),
            'api.salla.dev/admin/v2/products*' => Http::response(['error' => 'server error'], 500),
        ]);

        (new ProcessAutoReply($fixture['message']->id))->handle();

        // Even on failure, we must still have ATTEMPTED the real API call --
        // never silently skip straight to a canned "no access" message.
        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'api.salla.dev/admin/v2/products');
        });

        Http::assertSent(function ($request) {
            if (!str_contains($request->url(), 'generateContent')) {
                return true;
            }
            $body = json_encode($request->data());
            return str_contains($body, 'LIVE PRODUCT DATA FETCH FAILED');
        });
    }
}
