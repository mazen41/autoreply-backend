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
 * Regression tests for the "product browse vs place_order" misclassification bugs
 * found in production conversation_id 1031.
 *
 * Bug 1: "What products you have on salla" was not matched by aggregate detection
 *         ? bot asked for a rephrase instead of fetching Salla products.
 *
 * Bug 2: Follow-up "Yeah i wanna see the products fetch them please" was classified
 *         as place_order intent ? unnecessary escalation was triggered.
 */
class SallaProductBrowseIntentTest extends TestCase
{
    use RefreshDatabase;

    private function makeFreePackage(): Package
    {
        return Package::create([
            'name'             => 'Free',
            'name_ar'          => '?????',
            'price_monthly'    => 0,
            'price_yearly'     => 0,
            'ai_replies_limit' => -1,
            'is_active'        => true,
        ]);
    }

    private function makeFixture(string $inboundContent, ?string $priorAiMessage = null): array
    {
        $this->makeFreePackage();

        $user = User::forceCreate([
            'name'     => 'Test',
            'email'    => 'test' . rand() . '@test.com',
            'password' => '123',
        ]);

        $business = BusinessProfile::create([
            'user_id'       => $user->id,
            'business_name' => 'Test Store',
            'knowledge_base' => 'We are a great store.',
        ]);

        $whatsappChannel = Channel::create([
            'user_id'      => $user->id,
            'business_id'  => $business->id,
            'type'         => 'whatsapp',
            'page_id'      => 'instance-browse-test',
            'status'       => 'connected',
            'access_token' => 'test_token_browse',
            'ai_enabled'   => true,
        ]);

        $sallaChannel = Channel::create([
            'user_id'       => $user->id,
            'business_id'   => $business->id,
            'type'          => 'salla',
            'status'        => 'connected',
            'access_token'  => 'fake-salla-token',
            'refresh_token' => 'fake-refresh-token',
        ]);

        $conversation = Conversation::create([
            'channel_id'  => $whatsappChannel->id,
            'business_id' => $business->id,
            'sender_id'   => '966500000001',
            'status'      => 'open',
        ]);

        if ($priorAiMessage !== null) {
            Message::create([
                'conversation_id' => $conversation->id,
                'content'         => $priorAiMessage,
                'direction'       => 'outbound',
                'status'          => 'sent',
                'is_ai'           => true,
                'send_status'     => 'sent',
            ]);
        }

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'content'         => $inboundContent,
            'direction'       => 'inbound',
            'status'          => 'received',
            'is_ai'           => false,
            'send_status'     => 'received',
        ]);

        return compact('user', 'business', 'whatsappChannel', 'sallaChannel', 'conversation', 'message');
    }

    private function fakeSallaProducts(): array
    {
        return [
            'data' => [
                ['id' => 1, 'name' => 'Product A', 'price' => ['amount' => 50, 'currency_code' => 'SAR'], 'quantity' => 5],
                ['id' => 2, 'name' => 'Product B', 'price' => ['amount' => 75, 'currency_code' => 'SAR'], 'quantity' => 3],
            ],
            'pagination' => ['total' => 12, 'per_page' => 10, 'current_page' => 1],
        ];
    }

    private function fakeGemini(string $text, string $intent = 'question'): array
    {
        return ['candidates' => [['content' => ['parts' => [['text' => json_encode([
            'reply'             => $text,
            'intent'            => $intent,
            'needs_escalation'  => false,
            'confidence'        => 0.95,
            'escalation_reason' => 'none',
        ])]]]]]]];
    }

    /** Regression Bug 1: "What products you have on salla" must hit Salla API */
    public function test_natural_phrase_what_products_you_have_on_salla_triggers_aggregate(): void
    {
        $fixture = $this->makeFixture('What products you have on salla');
        Http::fake([
            'generativelanguage.googleapis.com/*embedContent*'    => Http::response(['embedding' => ['values' => []]], 200),
            'generativelanguage.googleapis.com/*generateContent*' => Http::response($this->fakeGemini('We have 12 products including Product A (50 SAR).'), 200),
            'api.salla.dev/admin/v2/products*'                    => Http::response($this->fakeSallaProducts(), 200),
        ]);

        (new ProcessAutoReply($fixture['message']->id))->handle();

        Http::assertSent(fn($r) => str_contains($r->url(), 'api.salla.dev/admin/v2/products'));

        $reply = Message::where('conversation_id', $fixture['conversation']->id)
            ->where('direction', 'outbound')->where('is_ai', true)->latest('id')->first();
        $this->assertNotNull($reply);
        $this->assertStringNotContainsString('rephrase', strtolower($reply->content));
    }

    /** Variant: "show me what you sell" */
    public function test_show_me_what_you_sell_triggers_aggregate(): void
    {
        $fixture = $this->makeFixture('show me what you sell');
        Http::fake([
            'generativelanguage.googleapis.com/*embedContent*'    => Http::response(['embedding' => ['values' => []]], 200),
            'generativelanguage.googleapis.com/*generateContent*' => Http::response($this->fakeGemini('Here are our products.'), 200),
            'api.salla.dev/admin/v2/products*'                    => Http::response($this->fakeSallaProducts(), 200),
        ]);
        (new ProcessAutoReply($fixture['message']->id))->handle();
        Http::assertSent(fn($r) => str_contains($r->url(), 'api.salla.dev/admin/v2/products'));
    }

    /**
     * Regression Bug 2: "Yeah i wanna see the products fetch them please"
     * after bot asked to rephrase ? must trigger aggregate, NOT escalate as place_order.
     */
    public function test_fetch_them_follow_up_after_rephrase_triggers_aggregate_not_escalation(): void
    {
        $priorBot = 'Could you please rephrase your question to something like "how many products do you have?" so I can help you better.';
        $fixture  = $this->makeFixture('Yeah i wanna see the products fetch them please', $priorBot);

        Http::fake([
            'generativelanguage.googleapis.com/*embedContent*'    => Http::response(['embedding' => ['values' => []]], 200),
            'generativelanguage.googleapis.com/*generateContent*' => Http::response($this->fakeGemini('Sure! Here are our 12 products: Product A (50 SAR), Product B (75 SAR).'), 200),
            'api.salla.dev/admin/v2/products*'                    => Http::response($this->fakeSallaProducts(), 200),
        ]);

        (new ProcessAutoReply($fixture['message']->id))->handle();

        Http::assertSent(fn($r) => str_contains($r->url(), 'api.salla.dev/admin/v2/products'));

        $reply = Message::where('conversation_id', $fixture['conversation']->id)
            ->where('direction', 'outbound')->where('is_ai', true)->latest('id')->first();
        $this->assertNotNull($reply);
        $this->assertStringNotContainsString('connecting you', strtolower($reply->content));
        $this->assertStringNotContainsString('team member', strtolower($reply->content));
    }

    /** Sanity: actual place_order phrasing must NOT trigger product aggregate in prompt */
    public function test_actual_buy_order_phrase_does_not_trigger_product_aggregate(): void
    {
        $fixture = $this->makeFixture('I want to order the blue shirt size L');
        Http::fake([
            'generativelanguage.googleapis.com/*embedContent*'    => Http::response(['embedding' => ['values' => []]], 200),
            'generativelanguage.googleapis.com/*generateContent*' => Http::response($this->fakeGemini('Great! May I have your name and address?', 'place_order'), 200),
            'api.salla.dev/admin/v2/products*'                    => Http::response($this->fakeSallaProducts(), 200),
        ]);

        (new ProcessAutoReply($fixture['message']->id))->handle();

        // Prompt sent to Gemini must NOT contain "Total products in store" (aggregate marker)
        Http::assertSent(function ($r) {
            if (!str_contains($r->url(), 'generateContent')) return true;
            return !str_contains(json_encode($r->data()), 'Total products in store');
        });
    }

    /** Arabic variant: "????? ????????" must trigger aggregate */
    public function test_arabic_show_me_products_triggers_aggregate(): void
    {
        $fixture = $this->makeFixture('????? ????????');
        Http::fake([
            'generativelanguage.googleapis.com/*embedContent*'    => Http::response(['embedding' => ['values' => []]], 200),
            'generativelanguage.googleapis.com/*generateContent*' => Http::response($this->fakeGemini('???? ????????: ?????? ? (50 ????).'), 200),
            'api.salla.dev/admin/v2/products*'                    => Http::response($this->fakeSallaProducts(), 200),
        ]);
        (new ProcessAutoReply($fixture['message']->id))->handle();
        Http::assertSent(fn($r) => str_contains($r->url(), 'api.salla.dev/admin/v2/products'));
    }
}
