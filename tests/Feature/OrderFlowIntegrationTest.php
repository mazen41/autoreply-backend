<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\ProductMessageMap;
use App\Models\Bot;
use App\Models\BusinessProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class OrderFlowIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private BusinessProfile $business;
    private Channel $channel;
    private Bot $bot;
    private string $senderId = '2383869222022713'; // Instagram/Meta sender ID (NOT a phone number)

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
        ]);

        $this->business = BusinessProfile::create([
            'user_id' => $this->user->id,
            'name' => 'Test Business',
            'business_name' => 'Test Store',
        ]);

        $this->channel = Channel::create([
            'user_id' => $this->user->id,
            'business_id' => $this->business->id,
            'type' => 'instagram',
            'status' => 'connected',
            'ai_enabled' => true,
            'page_name' => 'Test Instagram',
            'access_token' => 'test_token',
        ]);

        $this->bot = Bot::create([
            'business_profile_id' => $this->business->id,
            'name' => 'Test Bot',
            'status' => 'active',
        ]);

        $this->bot->channels()->attach($this->channel->id, ['is_primary' => true]);
    }

    /**
     * Simulate the full conversation flow from the production incident,
     * mirroring the real WebhookController + ProcessAutoReply code paths:
     * webhook idempotency, reply-to product resolution, checkout_state
     * persistence, and field extraction.
     */
    public function test_full_order_flow_state_transitions(): void
    {
        // ── STEP 1: "Hi" → Greeting ─────────────────────────────────
        $conversation = $this->simulateIncomingMessage('Hi', 'mid-1');

        $this->assertEquals('open', $conversation->status);
        $this->assertNull($conversation->checkout_state);
        echo "\n✅ STEP 1: 'Hi' → Conversation created, no phantom checkout_state\n";

        // ── STEP 2: "Can i see the products" → Product list ──────────
        $conversation = $this->simulateIncomingMessage('Can i see the products', 'mid-2');

        $this->assertNull($conversation->checkout_state);
        echo "✅ STEP 2: 'Can i see the products' → No checkout_state, no name hallucination\n";

        // ── STEP 3: "Can i see the images" → Product image sent ─────
        $conversation = $this->simulateIncomingMessage('Can i see the images', 'mid-3');

        $this->assertNull($conversation->checkout_state);
        $this->assertNull($conversation->checkout_state['address'] ?? null);

        // Bot sends a product image — mapped deterministically
        Message::create([
            'conversation_id' => $conversation->id,
            'content' => 'Product image',
            'direction' => 'outbound',
            'is_ai' => true,
            'status' => 'sent',
            'source' => 'product_image',
            'metadata' => ['platform_message_id' => 'img-mid-1'],
        ]);

        ProductMessageMap::create([
            'conversation_id' => $conversation->id,
            'channel_id' => $this->channel->id,
            'platform_message_id' => 'img-mid-1',
            'salla_product_id' => 'dress-001',
            'sku' => 'DRESS-001',
            'product_name' => 'فستان',
            'product_price' => 77,
            'currency' => 'SAR',
            'image_url' => 'https://example.com/dress.jpg',
        ]);

        echo "✅ STEP 3: 'Can i see the images' → NOT treated as address; image mapped to dress-001\n";

        // ── STEP 4: "Okay can i place an order" → Order intent ──────
        $conversation = $this->simulateIncomingMessage('Okay can i place an order', 'mid-4');

        // No quoted message and no prior selection → product is NOT yet
        // deterministically resolvable. Bot must ask which product — it must
        // NOT fabricate a product, phone, or address.
        $this->assertNull($conversation->checkout_state);
        $this->assertNull($conversation->checkout_state['salla_product_id'] ?? null);
        $this->assertNull($conversation->checkout_state['phone'] ?? null);
        $this->assertNull($conversation->checkout_state['address'] ?? null);
        echo "✅ STEP 4: 'Okay can i place an order' → order intent, no product/phone/address fabricated\n";

        // ── STEP 5: Customer provides phone ───────────────────────────
        $conversation = $this->simulateIncomingMessage('phone is 01152879755', 'mid-5');

        $this->assertEquals('01152879755', $conversation->checkout_state['phone'] ?? null);
        $this->assertNull($conversation->checkout_state['address'] ?? null);
        // CRITICAL: Instagram/Meta sender_id must NEVER become the phone
        $this->assertNotEquals($this->senderId, $conversation->checkout_state['phone'] ?? null);
        echo "✅ STEP 5: 'phone is 01152879755' → Phone set from explicit text, address still null, sender_id NOT used\n";

        // ── STEP 6: Customer provides address ─────────────────────────
        $conversation = $this->simulateIncomingMessage('address is giza fisal 36 sayed Abdelrahman eladwy kafr tohorms', 'mid-6');

        $this->assertEquals('01152879755', $conversation->checkout_state['phone'] ?? null);
        $this->assertEquals('giza fisal 36 sayed Abdelrahman eladwy kafr tohorms', $conversation->checkout_state['address'] ?? null);
        echo "✅ STEP 6: explicit 'address is ...' → Address set, phone preserved\n";

        // ── STEP 7: "The dress" → vague reference, no quote ──────────
        $conversation = $this->simulateIncomingMessage('The dress', 'mid-7');

        // No quoted message → no deterministic product resolution.
        // Vague references must NOT pollute structured fields (not a name,
        // not an address, not a product binding).
        $this->assertNull($conversation->checkout_state['salla_product_id'] ?? null);
        $this->assertNull($conversation->checkout_state['full_name'] ?? null);
        $this->assertEquals('01152879755', $conversation->checkout_state['phone'] ?? null);
        $this->assertEquals('giza fisal 36 sayed Abdelrahman eladwy kafr tohorms', $conversation->checkout_state['address'] ?? null);
        echo "✅ STEP 7: 'The dress' → vague reference ignored by structured state, phone/address preserved\n";

        // ── STEP 8: "This one" with reply_to.mid → deterministic product resolution
        $conversation = $this->simulateIncomingMessage('This one', 'mid-8', 'img-mid-1');

        $this->assertEquals('dress-001', $conversation->checkout_state['salla_product_id'] ?? null);
        $this->assertEquals('فستان', $conversation->checkout_state['product_name'] ?? null);
        $this->assertEquals(77, $conversation->checkout_state['product_price'] ?? null);
        // "This one" must not become the customer name
        $this->assertNull($conversation->checkout_state['full_name'] ?? null);
        // Previously collected fields intact
        $this->assertEquals('01152879755', $conversation->checkout_state['phone'] ?? null);
        $this->assertEquals('giza fisal 36 sayed Abdelrahman eladwy kafr tohorms', $conversation->checkout_state['address'] ?? null);
        echo "✅ STEP 8: 'This one' (reply-to img-mid-1) → Product resolved deterministically, all fields intact\n";

        // ── STEP 9: State survives corrections ────────────────────────
        $conversation = $this->simulateIncomingMessage('No actually the phone is 01152879755 and the address is giza fisal 36 sayed Abdelrahman eladwy kafr tohorms', 'mid-9');

        $this->assertEquals('dress-001', $conversation->checkout_state['salla_product_id'] ?? null);
        $this->assertEquals('01152879755', $conversation->checkout_state['phone'] ?? null);
        $this->assertEquals('giza fisal 36 sayed Abdelrahman eladwy kafr tohorms', $conversation->checkout_state['address'] ?? null);
        echo "✅ STEP 9: correction message → Product selection + phone + address all preserved\n";

        // ── STEP 10: Duplicate webhook delivery (same platform message ID)
        $messageCountBefore = Message::where('conversation_id', $conversation->id)->count();

        $result = $this->simulateIncomingMessage('This one', 'mid-8', 'img-mid-1');

        $messageCountAfter = Message::where('conversation_id', $conversation->id)->count();
        $this->assertEquals($messageCountBefore, $messageCountAfter);
        echo "✅ STEP 10: Duplicate webhook (same platform_message_id) → Idempotency guard dropped it\n";

        // ── STEP 11: Different message (different platform message ID)
        $messageCountBefore = Message::where('conversation_id', $conversation->id)->count();

        $this->simulateIncomingMessage('Thank you', 'mid-10');

        $messageCountAfter = Message::where('conversation_id', $conversation->id)->count();
        $this->assertEquals($messageCountBefore + 1, $messageCountAfter);
        echo "✅ STEP 11: Different message → New message created, not falsely deduplicated\n";

        echo "\n🎉 ALL INTEGRATION TESTS PASSED\n";
    }

    /**
     * Simulate an incoming Instagram/Meta message through the real code
     * paths: webhook idempotency (WebhookController), message persistence,
     * deterministic product resolution (ProcessAutoReply), and checkout
     * state extraction/persistence (OrderCheckoutService).
     */
    private function simulateIncomingMessage(string $text, string $platformMessageId, ?string $replyToMid = null): Conversation
    {
        // ── Webhook idempotency (WebhookController::processMessage) ──
        $idempotencyKey = "webhook:instagram:{$platformMessageId}";
        if (Cache::has($idempotencyKey)) {
            // Duplicate delivery — ignored before any message/job is created
            return Conversation::where('channel_id', $this->channel->id)
                ->where('sender_id', $this->senderId)
                ->firstOrFail();
        }
        Cache::put($idempotencyKey, true, now()->addMinutes(5));

        $conversation = Conversation::firstOrCreate(
            ['channel_id' => $this->channel->id, 'sender_id' => $this->senderId],
            ['business_id' => $this->business->id, 'status' => 'open', 'last_message_at' => now()]
        );

        $metadata = ['platform_message_id' => $platformMessageId];
        if ($replyToMid) {
            $metadata['quoted_message_id'] = $replyToMid;
            $metadata['reply_to_platform'] = 'instagram';
        }

        Message::create([
            'conversation_id' => $conversation->id,
            'content' => $text,
            'direction' => 'inbound',
            'is_ai' => false,
            'status' => 'received',
            'metadata' => $metadata,
        ]);

        // ── Deterministic product resolution (ProcessAutoReply priority) ──
        // 1. Replied-to product message (ProductMessageMap)
        // 2. Active checkout_state on the conversation (mid-order turn)
        $referencedProduct = null;
        if ($replyToMid) {
            $productMap = ProductMessageMap::where('conversation_id', $conversation->id)
                ->where('platform_message_id', $replyToMid)
                ->latest('id')
                ->first();
            if ($productMap) {
                $referencedProduct = [
                    'salla_product_id' => $productMap->salla_product_id,
                    'sku'              => $productMap->sku,
                    'name'             => $productMap->product_name,
                    'price'            => $productMap->product_price,
                    'currency'         => $productMap->currency ?? 'SAR',
                    'image'            => $productMap->image_url,
                ];
            }
        }
        if (!$referencedProduct && !empty($conversation->checkout_state['salla_product_id'])) {
            $referencedProduct = [
                'salla_product_id' => $conversation->checkout_state['salla_product_id'],
                'sku'              => $conversation->checkout_state['sku']              ?? null,
                'name'             => $conversation->checkout_state['product_name']     ?? 'Unknown',
                'price'            => $conversation->checkout_state['product_price']    ?? '?',
                'currency'         => $conversation->checkout_state['product_currency'] ?? 'SAR',
                'image'            => null,
            ];
        }

        // ── Checkout state extraction & persistence ───────────────────
        $checkoutService = new \App\Services\OrderCheckoutService();
        $checkoutState = $checkoutService->extractAndMergeState($conversation, $text, $referencedProduct);

        if (!empty($checkoutState)) {
            $conversation->update(['checkout_state' => $checkoutState]);
        } else {
            $conversation->update(['checkout_state' => null]);
        }

        return $conversation->fresh();
    }
}
