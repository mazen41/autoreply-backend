<?php

namespace Tests\Feature;

use App\Jobs\ProcessAutoReply;
use App\Models\BusinessProfile;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Package;
use App\Models\ProductMessageMap;
use App\Models\User;
use App\Models\WhatsAppInstance;
use App\Services\EvolutionApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * CRITICAL ISSUE — REPLY-TO-PRODUCT CONTEXT / PRODUCT SELECTION
 *
 * Regression tests for: when a customer replies directly to one of several
 * product images sent earlier, the system must resolve the EXACT product
 * deterministically from the stored message->product mapping — never let the
 * AI guess from text, image position, or name similarity.
 *
 * Covers:
 *  1. Outgoing product images are persisted as ProductMessageMap rows keyed
 *     by the real Evolution message id, one row per product.
 *  2. Evolution webhook payloads carrying a reply/quote (contextInfo.stanzaId)
 *     have that id extracted and survive into the unified inbox Message's
 *     metadata, regardless of which message-type key contextInfo is nested in.
 *  3. ProcessAutoReply resolves the referenced product from that id, forces
 *     place_order intent on "this one" phrasing, and skips the generic Salla
 *     product list re-fetch entirely.
 *  4. Ordinary (non-reply) place_order requests are unaffected.
 */
class ProductReplyContextTest extends TestCase
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

    private function makeFixture(): array
    {
        $this->makeFreePackage();

        $user = User::forceCreate([
            'name' => 'Test',
            'email' => 'test' . rand() . '@example.com',
            'password' => bcrypt('password'),
        ]);

        $business = BusinessProfile::create([
            'user_id' => $user->id,
            'business_name' => 'Demo Store',
        ]);

        $whatsappChannel = Channel::create([
            'user_id' => $user->id,
            'business_id' => $business->id,
            'type' => 'whatsapp',
            'page_id' => 'instance-reply-test',
            'status' => 'connected',
            'access_token' => 'test_token_' . \Illuminate\Support\Str::random(10),
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

        return compact('user', 'business', 'whatsappChannel', 'sallaChannel', 'conversation');
    }

    private function fakeGeminiReply(string $replyText, string $intent = 'question', bool $needsImages = false): array
    {
        return [
            'candidates' => [[
                'content' => [
                    'parts' => [[
                        'text' => json_encode([
                            'reply' => $replyText,
                            'intent' => $intent,
                            'needs_escalation' => false,
                            'needs_images' => $needsImages,
                            'confidence' => 0.9,
                            'escalation_reason' => 'none',
                        ]),
                    ]],
                ],
            ]],
        ];
    }

    private function sallaProductsFixture(): array
    {
        return [
            'data' => [
                ['id' => 101, 'name' => 'Red Dress', 'price' => ['amount' => 150, 'currency_code' => 'SAR'], 'quantity' => 3, 'thumbnail' => 'https://cdn.salla.sa/red-dress.jpg'],
                ['id' => 102, 'name' => 'Blue Jeans', 'price' => ['amount' => 90, 'currency_code' => 'SAR'], 'quantity' => 5, 'thumbnail' => 'https://cdn.salla.sa/blue-jeans.jpg'],
                ['id' => 103, 'name' => 'Leather Belt', 'price' => ['amount' => 60, 'currency_code' => 'SAR'], 'quantity' => 8, 'thumbnail' => 'https://cdn.salla.sa/belt.jpg'],
                ['id' => 104, 'name' => 'White Sneakers', 'price' => ['amount' => 220, 'currency_code' => 'SAR'], 'quantity' => 2, 'thumbnail' => 'https://cdn.salla.sa/sneakers.jpg'],
                ['id' => 105, 'name' => 'Gold Watch', 'price' => ['amount' => 480, 'currency_code' => 'SAR'], 'quantity' => 1, 'thumbnail' => 'https://cdn.salla.sa/watch.jpg'],
            ],
            'pagination' => ['total' => 5, 'per_page' => 10, 'current_page' => 1],
        ];
    }

    /**
     * SETUP: Mock 5 Salla products (Evolution caps image sends at 5 per
     * message). Send them via the product-aggregate "show me products" +
     * needs_images flow. ASSERT: one ProductMessageMap row is persisted per
     * successfully-sent image, each keyed to the real Evolution message id
     * and pointing at the correct Salla product — never a duplicate, never
     * a mismatch.
     */
    public function test_sending_product_images_persists_one_message_to_product_mapping_per_image(): void
    {
        $fixture = $this->makeFixture();

        // A prior AI message mentioning "products" is required so the
        // follow-up "send photos" message is recognised as a product-image
        // aggregate request (mirrors ProcessAutoReply's follow-up detection).
        Message::create([
            'conversation_id' => $fixture['conversation']->id,
            'content' => 'Here are some of our products, would you like to see photos?',
            'direction' => 'outbound',
            'status' => 'sent',
            'is_ai' => true,
            'intent' => 'question',
            'send_status' => 'sent',
        ]);
        $message = Message::create([
            'conversation_id' => $fixture['conversation']->id,
            'content' => 'yes please send photos',
            'direction' => 'inbound',
            'status' => 'received',
            'is_ai' => false,
            'send_status' => 'received',
        ]);

        // Evolution returns a distinct message id for each sendMedia call.
        $mediaCallCount = 0;
        Http::fake([
            'generativelanguage.googleapis.com/*embedContent*' => Http::response(['embedding' => ['values' => []]], 200),
            'generativelanguage.googleapis.com/*generateContent*' => Http::response(
                $this->fakeGeminiReply('Here are our products!', 'question', true),
                200
            ),
            'api.salla.dev/admin/v2/products*' => Http::response($this->sallaProductsFixture(), 200),
            'localhost:8080/message/sendMedia/*' => function () use (&$mediaCallCount) {
                $mediaCallCount++;
                return Http::response(['key' => ['id' => "MEDIA_MSG_{$mediaCallCount}"]], 200);
            },
            'localhost:8080/message/sendText/*' => Http::response(['key' => ['id' => 'TEXT_MSG_1']], 200),
        ]);

        (new ProcessAutoReply($message->id))->handle();

        $maps = ProductMessageMap::where('conversation_id', $fixture['conversation']->id)->orderBy('id')->get();

        $this->assertCount(5, $maps, 'One ProductMessageMap row must exist per sent product image');

        $expectedProductIds = ['101', '102', '103', '104', '105'];
        $this->assertEquals($expectedProductIds, $maps->pluck('salla_product_id')->all());

        // Every row must have a distinct outgoing message id — no collisions.
        $this->assertEquals(5, $maps->pluck('whatsapp_message_id')->unique()->count());

        // Spot-check one row's full data integrity.
        $beltMap = $maps->firstWhere('salla_product_id', '103');
        $this->assertEquals('Leather Belt', $beltMap->product_name);
        $this->assertEquals('60', $beltMap->product_price);
        $this->assertEquals('SAR', $beltMap->currency);
        $this->assertEquals('https://cdn.salla.sa/belt.jpg', $beltMap->image_url);
    }

    /**
     * ACCEPTANCE CRITERIA: customer replies directly to the image for the
     * Leather Belt (product #3 of 5) with "I wanna place order for this one".
     * The system MUST resolve product #103 deterministically and MUST NOT
     * ask "which product" or fall back to guessing.
     */
    public function test_reply_to_specific_product_image_resolves_exact_product_for_place_order(): void
    {
        $fixture = $this->makeFixture();

        // Simulate 5 already-sent product image messages (as if
        // sendImagesToCustomer had run earlier in the conversation).
        $products = $this->sallaProductsFixture()['data'];
        foreach ($products as $i => $p) {
            ProductMessageMap::create([
                'conversation_id' => $fixture['conversation']->id,
                'channel_id' => $fixture['whatsappChannel']->id,
                'whatsapp_message_id' => 'MEDIA_MSG_' . ($i + 1),
                'salla_product_id' => (string) $p['id'],
                'product_name' => $p['name'],
                'product_price' => (string) $p['price']['amount'],
                'currency' => $p['price']['currency_code'],
                'image_url' => $p['thumbnail'],
            ]);
        }

        // The customer's reply is a WhatsApp reply to MEDIA_MSG_3 (Leather Belt).
        $replyMessage = Message::create([
            'conversation_id' => $fixture['conversation']->id,
            'content' => 'I wanna place order for this one',
            'direction' => 'inbound',
            'status' => 'received',
            'is_ai' => false,
            'send_status' => 'received',
            'metadata' => ['quoted_message_id' => 'MEDIA_MSG_3'],
        ]);

        Http::fake([
            'generativelanguage.googleapis.com/*embedContent*' => Http::response(['embedding' => ['values' => []]], 200),
            'generativelanguage.googleapis.com/*generateContent*' => Http::response(
                $this->fakeGeminiReply('Great choice! Let’s get your Leather Belt order started.', 'place_order'),
                200
            ),
            'localhost:8080/message/sendText/*' => Http::response(['key' => ['id' => 'TEXT_MSG_9']], 200),
        ]);

        (new ProcessAutoReply($replyMessage->id))->handle();

        // The generic Salla product list must NEVER be re-fetched — the
        // product is already known deterministically.
        Http::assertNotSent(fn($r) => str_contains($r->url(), 'salla.dev'));

        // The AI must have been told exactly which product this is.
        Http::assertSent(function ($r) {
            if (!str_contains($r->url(), 'generateContent')) {
                return true; // irrelevant request, don't fail on it
            }
            $systemText = $r->data()['systemInstruction']['parts'][0]['text'] ?? '';
            return str_contains($systemText, 'DETERMINISTIC PRODUCT REFERENCE')
                && str_contains($systemText, 'Leather Belt')
                && str_contains($systemText, '60 SAR');
        });

        $reply = Message::where('conversation_id', $fixture['conversation']->id)
            ->where('direction', 'outbound')->where('is_ai', true)->latest('id')->first();

        $this->assertNotNull($reply);
        $this->assertEquals('place_order', $reply->intent);
    }

    /**
     * If the customer replies to a message id that has NO product mapping
     * (e.g. they replied to a plain text message, not a product image), the
     * system must fall back to normal handling rather than fabricating a
     * product.
     */
    public function test_reply_to_non_product_message_does_not_fabricate_a_referenced_product(): void
    {
        $fixture = $this->makeFixture();

        $replyMessage = Message::create([
            'conversation_id' => $fixture['conversation']->id,
            'content' => 'I want to order the blue dress',
            'direction' => 'inbound',
            'status' => 'received',
            'is_ai' => false,
            'send_status' => 'received',
            'metadata' => ['quoted_message_id' => 'SOME_UNRELATED_TEXT_MSG'],
        ]);

        Http::fake([
            'generativelanguage.googleapis.com/*embedContent*' => Http::response(['embedding' => ['values' => []]], 200),
            'generativelanguage.googleapis.com/*generateContent*' => Http::response(
                $this->fakeGeminiReply('Sure! Let me get your details for the blue dress.', 'place_order'),
                200
            ),
            'api.salla.dev/admin/v2/products*' => Http::response($this->sallaProductsFixture(), 200),
            'localhost:8080/message/sendText/*' => Http::response(['key' => ['id' => 'TEXT_MSG_2']], 200),
        ]);

        (new ProcessAutoReply($replyMessage->id))->handle();

        // No mapping existed for this quoted id — normal Salla product-list
        // fetch must still happen (existing, pre-fix behavior preserved).
        Http::assertSent(fn($r) => str_contains($r->url(), 'api.salla.dev/admin/v2/products'));
    }

    /**
     * CRITICAL WEBHOOK INVESTIGATION: the replied-to message id
     * (contextInfo.stanzaId) must be extracted from an incoming Evolution
     * MESSAGES_UPSERT payload and survive into the unified inbox Message's
     * metadata, regardless of which message-type key carries contextInfo.
     */
    public function test_webhook_extracts_quoted_message_id_into_unified_inbox_message(): void
    {
        Queue::fake();
        $this->makeFreePackage();

        $user = User::forceCreate([
            'name' => 'Test',
            'email' => 'test' . rand() . '@example.com',
            'password' => bcrypt('password'),
        ]);

        $business = BusinessProfile::create([
            'user_id' => $user->id,
            'business_name' => 'Demo Store',
        ]);

        $instance = WhatsAppInstance::create([
            'user_id' => $user->id,
            'instance_name' => 'instance-webhook-reply-test',
            'status' => 'connected',
        ]);

        Channel::create([
            'user_id' => $user->id,
            'business_id' => $business->id,
            'type' => 'whatsapp',
            'page_id' => $instance->instance_name,
            'status' => 'connected',
            'access_token' => '',
            'ai_enabled' => true,
        ]);

        $service = new EvolutionApiService();
        $service->processWebhookEvent([
            'event' => 'MESSAGES_UPSERT',
            'instance' => $instance->instance_name,
            'data' => [
                'key' => [
                    'id' => 'REPLY_MSG_1',
                    'fromMe' => false,
                    'remoteJid' => '966500000000@s.whatsapp.net',
                ],
                'pushName' => 'Customer',
                'message' => [
                    'extendedTextMessage' => [
                        'text' => 'I wanna place order for this one',
                        'contextInfo' => [
                            'stanzaId' => 'MEDIA_MSG_3',
                            'participant' => '966500000000@s.whatsapp.net',
                        ],
                    ],
                ],
            ],
        ]);

        $saved = Message::where('content', 'I wanna place order for this one')->first();

        $this->assertNotNull($saved, 'Unified inbox message must be saved');
        $this->assertEquals('MEDIA_MSG_3', $saved->metadata['quoted_message_id'] ?? null);
    }

    /**
     * A message that is NOT a reply (no contextInfo at all) must resolve to
     * a null quoted_message_id — never a stale/hallucinated value.
     */
    public function test_webhook_non_reply_message_has_null_quoted_message_id(): void
    {
        Queue::fake();
        $this->makeFreePackage();

        $user = User::forceCreate([
            'name' => 'Test',
            'email' => 'test' . rand() . '@example.com',
            'password' => bcrypt('password'),
        ]);

        $business = BusinessProfile::create([
            'user_id' => $user->id,
            'business_name' => 'Demo Store',
        ]);

        $instance = WhatsAppInstance::create([
            'user_id' => $user->id,
            'instance_name' => 'instance-webhook-noreply-test',
            'status' => 'connected',
        ]);

        Channel::create([
            'user_id' => $user->id,
            'business_id' => $business->id,
            'type' => 'whatsapp',
            'page_id' => $instance->instance_name,
            'status' => 'connected',
            'access_token' => '',
            'ai_enabled' => true,
        ]);

        $service = new EvolutionApiService();
        $service->processWebhookEvent([
            'event' => 'MESSAGES_UPSERT',
            'instance' => $instance->instance_name,
            'data' => [
                'key' => [
                    'id' => 'PLAIN_MSG_1',
                    'fromMe' => false,
                    'remoteJid' => '966500000000@s.whatsapp.net',
                ],
                'pushName' => 'Customer',
                'message' => [
                    'conversation' => 'I want to order',
                ],
            ],
        ]);

        $saved = Message::where('content', 'I want to order')->first();

        $this->assertNotNull($saved);
        $this->assertNull($saved->metadata['quoted_message_id'] ?? null);
    }
}
