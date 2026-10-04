<?php

namespace Tests\Feature;

use App\Jobs\FetchSenderName;
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
use App\Services\EvolutionApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Phase 2 — reliable Conversation.customer_id.
 *
 * Covers:
 *   • customer association        (webhook inbound → customer linked)
 *   • multi-channel resolution    (WhatsApp phone ≡ Salla mobile ≡ checkout phone)
 *   • platform-identity dedup     (same IG sender → same customer, no dupes)
 *   • AI-turn checkout backfill   (Instagram conversation unified with the
 *                                  customer that owns the collected phone)
 *   • name backfill               (FetchSenderName updates the customer)
 */
class CustomerResolutionTest extends TestCase
{
    use RefreshDatabase;

    private function makeBusiness(): array
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

        return compact('user', 'business');
    }

    private function makeChannel(array $fixture, string $type, array $overrides = []): Channel
    {
        return Channel::create(array_merge([
            'user_id' => $fixture['user']->id,
            'business_id' => $fixture['business']->id,
            'type' => $type,
            'page_id' => 'acc-' . $type . '-' . rand(1000, 9999),
            'status' => 'connected',
            'access_token' => 'DUMMY-TOKEN',
            'ai_enabled' => true,
        ], $overrides));
    }

    // ── Service-level resolution ─────────────────────────────────────────────

    public function test_same_phone_on_whatsapp_and_salla_resolves_to_one_customer(): void
    {
        $fixture = $this->makeBusiness();
        $service = app(CustomerService::class);
        $businessId = $fixture['business']->id;

        $a = $service->resolve($businessId, 'whatsapp', '966555000111', ['phone' => '966555000111', 'name' => 'Ahmed']);
        $b = $service->resolve($businessId, 'salla', '+966555000111', ['phone' => '+966555000111', 'name' => 'Ahmed']);

        $this->assertSame($a->id, $b->id, 'Same phone on different channels must resolve to one customer');
        $this->assertSame(1, Customer::where('business_profile_id', $businessId)->count(), 'No duplicate customer records may be created');

        // The platform identity of the second channel is recorded for future
        // phone-less matching.
        $this->assertSame('+966555000111', $a->refresh()->custom_fields['platform_ids']['salla'] ?? null);
    }

    public function test_same_platform_sender_resolves_to_one_customer(): void
    {
        $fixture = $this->makeBusiness();
        $service = app(CustomerService::class);
        $businessId = $fixture['business']->id;

        $a = $service->resolve($businessId, 'instagram', 'ig-user-42');
        $b = $service->resolve($businessId, 'instagram', 'ig-user-42', ['name' => 'Sara']);

        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, Customer::where('business_profile_id', $businessId)->count());
        // Name backfilled on the second sighting.
        $this->assertSame('Sara', $a->refresh()->name);
    }

    public function test_different_senders_get_distinct_customers(): void
    {
        $fixture = $this->makeBusiness();
        $service = app(CustomerService::class);
        $businessId = $fixture['business']->id;

        $a = $service->resolve($businessId, 'instagram', 'ig-user-1');
        $b = $service->resolve($businessId, 'instagram', 'ig-user-2');

        $this->assertNotSame($a->id, $b->id);
        $this->assertSame(2, Customer::where('business_profile_id', $businessId)->count());
    }

    public function test_customers_are_never_shared_across_businesses(): void
    {
        $fixtureA = $this->makeBusiness();
        $fixtureB = $this->makeBusiness();
        $service = app(CustomerService::class);

        $a = $service->resolve($fixtureA['business']->id, 'instagram', 'ig-user-1');
        $b = $service->resolve($fixtureB['business']->id, 'instagram', 'ig-user-1');

        $this->assertNotSame($a->id, $b->id);
        $this->assertSame(2, Customer::count());
    }

    public function test_phone_placeholder_names_are_never_stored(): void
    {
        $fixture = $this->makeBusiness();
        $service = app(CustomerService::class);

        $customer = $service->resolve($fixture['business']->id, 'whatsapp', '966555000111', ['phone' => '966555000111', 'name' => '+966555000111']);

        $this->assertNull($customer->name);
    }

    // ── Inbound webhook E2E (Meta: Instagram/Facebook DMs) ───────────────────

    public function test_meta_webhook_links_customer_to_new_conversation(): void
    {
        config(['services.meta.app_secret' => 'test-meta-secret']);
        Queue::fake();

        $fixture = $this->makeBusiness();
        $this->makeChannel($fixture, 'instagram', ['page_id' => '17841400000000099']);

        $payload = [
            'object' => 'instagram',
            'entry' => [[
                'id' => '17841400000000099',
                'messaging' => [[
                    'sender' => ['id' => 'ig-sender-777'],
                    'recipient' => ['id' => '17841400000000099'],
                    'message' => ['mid' => 'mid_' . uniqid(), 'text' => 'Hello!'],
                ]],
            ]],
        ];

        $body = json_encode($payload);
        $signature = 'sha256=' . hash_hmac('sha256', $body, 'test-meta-secret');

        $response = $this->call(
            'POST',
            '/api/webhook/meta',
            [],
            [],
            [],
            [
                'HTTP_X-HUB-SIGNATURE-256' => $signature,
                'CONTENT_TYPE' => 'application/json',
            ],
            $body
        );

        $response->assertStatus(200);

        $conversation = Conversation::where('sender_id', 'ig-sender-777')->first();
        $this->assertNotNull($conversation, 'Conversation must be created by the webhook');
        $this->assertNotNull($conversation->customer_id, 'Conversation must be linked to a customer at inbound time');

        $customer = Customer::find($conversation->customer_id);
        $this->assertSame('ig-sender-777', $customer->custom_fields['platform_ids']['instagram'] ?? null);
    }

    public function test_meta_webhook_duplicate_delivery_keeps_single_customer(): void
    {
        config(['services.meta.app_secret' => 'test-meta-secret']);
        Queue::fake();

        $fixture = $this->makeBusiness();
        $this->makeChannel($fixture, 'instagram', ['page_id' => '17841400000000100']);

        $mid = 'mid_dup_' . uniqid();
        $payload = [
            'object' => 'instagram',
            'entry' => [[
                'id' => '17841400000000100',
                'messaging' => [[
                    'sender' => ['id' => 'ig-sender-888'],
                    'recipient' => ['id' => '17841400000000100'],
                    'message' => ['mid' => $mid, 'text' => 'Hi again'],
                ]],
            ]],
        ];

        $send = function () use ($payload) {
            $body = json_encode($payload);
            $signature = 'sha256=' . hash_hmac('sha256', $body, 'test-meta-secret');
            $this->call('POST', '/api/webhook/meta', [], [], [], [
                'HTTP_X-HUB-SIGNATURE-256' => $signature,
                'CONTENT_TYPE' => 'application/json',
            ], $body)->assertStatus(200);
        };

        $send(); // first delivery
        Cache::forget("webhook:instagram:{$mid}"); // force re-processing of a NEW message text
        $payload['entry'][0]['messaging'][0]['message'] = ['mid' => $mid . '_b', 'text' => 'Second message'];
        $send();

        $this->assertSame(1, Customer::where('business_profile_id', $fixture['business']->id)->count());
        $this->assertSame(1, Conversation::where('sender_id', 'ig-sender-888')->count());
    }

    // ── WhatsApp inbound via Evolution ────────────────────────────────────────

    public function test_whatsapp_inbound_creates_customer_with_phone(): void
    {
        Queue::fake();

        $fixture = $this->makeBusiness();
        $this->makeChannel($fixture, 'whatsapp', ['page_id' => 'wa-instance-1']);

        // Evolution's inbound handler resolves the channel via its
        // WhatsAppInstance row (instance_name = channel page_id).
        \App\Models\WhatsAppInstance::create([
            'user_id' => $fixture['user']->id,
            'instance_name' => 'wa-instance-1',
            'status' => 'connected',
        ]);

        $service = new EvolutionApiService();
        $service->processWebhookEvent([
            'event' => 'MESSAGES_UPSERT',
            'instance' => 'wa-instance-1',
            'data' => [
                'key' => [
                    'id' => 'WA_MSG_' . uniqid(),
                    'fromMe' => false,
                    'remoteJid' => '966555000111@s.whatsapp.net',
                ],
                'pushName' => 'Faisal',
                'message' => ['conversation' => 'Where is my order?'],
            ],
        ]);

        // Evolution keys conversations on the raw JID — the CustomerService
        // must canonicalize it to the bare phone number.
        $conversation = Conversation::where('sender_id', '966555000111@s.whatsapp.net')->first();
        $this->assertNotNull($conversation, 'WhatsApp conversation must exist');
        $this->assertNotNull($conversation->customer_id, 'WhatsApp inbound must link a customer');

        $customer = Customer::find($conversation->customer_id);
        $this->assertSame('966555000111', $customer->phone, 'JID suffix must be stripped in the canonical phone');
        $this->assertSame('Faisal', $customer->name);
    }

    // ── Multi-channel unification through the AI turn (checkout backfill) ────

    public function test_instagram_conversation_unifies_with_whatsapp_customer_via_checkout_phone(): void
    {
        $fixture = $this->makeBusiness();

        $igChannel = $this->makeChannel($fixture, 'instagram', ['page_id' => '17841400000000200']);

        // The customer exists from a prior WhatsApp purchase, phone known.
        $whatsappCustomer = app(CustomerService::class)->resolve(
            $fixture['business']->id,
            'whatsapp',
            '966555000111',
            ['phone' => '966555000111', 'name' => 'Faisal']
        );

        // Instagram conversation: platform id only, but checkout flow collected
        // a phone in Saudi local format (05...).
        $conversation = Conversation::create([
            'channel_id' => $igChannel->id,
            'business_id' => $fixture['business']->id,
            'sender_id' => 'ig-sender-313',
            'status' => 'open',
            'checkout_state' => [
                'full_name' => 'Faisal',
                'phone' => '0555000111',
                'address' => 'Riyadh',
                'product_name' => 'Navy Dress',
            ],
        ]);

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'content' => 'Yes confirm the order',
            'direction' => 'inbound',
            'status' => 'received',
            'is_ai' => false,
            'send_status' => 'received',
        ]);

        config(['services.groq.api_key' => 'test-key']);
        Http::fake([
            'api.groq.com/openai/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => ['content' => json_encode([
                        'reply' => 'Order confirmed!',
                        'intent' => 'place_order',
                        'needs_escalation' => false,
                        'needs_images' => false,
                        'confidence' => 0.95,
                        'escalation_reason' => 'none',
                    ])],
                ]],
            ], 200),
            'graph.facebook.com/*' => Http::response(['message_id' => 'mid_fake'], 200),
        ]);

        (new ProcessAutoReply($message->id))->handle();

        $conversation->refresh();
        $this->assertNotNull(
            $conversation->customer_id,
            'AI turn must link the phone-less Instagram conversation to a customer'
        );
        $this->assertSame(
            $whatsappCustomer->id,
            $conversation->customer_id,
            'Checkout phone (0555000111) must unify with the WhatsApp customer (966555000111)'
        );
        $this->assertSame(1, Customer::where('business_profile_id', $fixture['business']->id)->count());
    }

    // ── Name backfill from FetchSenderName ────────────────────────────────────

    public function test_fetch_sender_name_backfills_customer_name(): void
    {
        $fixture = $this->makeBusiness();
        $channel = $this->makeChannel($fixture, 'instagram', ['page_id' => '17841400000000300']);

        $conversation = Conversation::create([
            'channel_id' => $channel->id,
            'business_id' => $fixture['business']->id,
            'sender_id' => 'ig-sender-90210',
            'status' => 'open',
        ]);

        app(CustomerService::class)->attachToConversation($conversation);
        $conversation->refresh();
        $this->assertNotNull($conversation->customer_id);
        $this->assertNull(Customer::find($conversation->customer_id)->name);

        Http::fake([
            'graph.facebook.com/v19.0/ig-sender-90210*' => Http::response(['name' => 'Mona'], 200),
        ]);

        (new FetchSenderName($conversation->id, $channel->id, 'ig-sender-90210'))->handle();

        $this->assertSame('Mona', $conversation->fresh()->sender_name);
        $this->assertSame('Mona', Customer::find($conversation->customer_id)->name, 'Customer name must be backfilled');
    }
}
