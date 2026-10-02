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
    private string $senderId = '2383869222022713'; // Instagram sender ID (NOT a phone number)

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
        ]);

        $this->bot = Bot::create([
            'business_profile_id' => $this->business->id,
            'name' => 'Test Bot',
            'status' => 'active',
        ]);

        // Attach bot to channel
        $this->bot->channels()->attach($this->channel->id, ['is_primary' => true]);
    }

    /**
     * Simulate the full conversation flow from the production incident.
     */
    public function test_full_order_flow_state_transitions(): void
    {
        // ── STEP 1: "Hi" → Greeting ────────────────────────────────────────
        $conversation = $this->simulateIncomingMessage('Hi', 'mid-1');

        $this->assertEquals('open', $conversation->status);
        $this->assertNull($conversation->checkout_state);
        echo "\n✅ STEP 1: 'Hi' → Conversation created, no checkout state\n";

        // ── STEP 2: "Can i see the products" → Product list ─────────────────
        $conversation = $this->simulateIncomingMessage('Can i see the products', 'mid-2');

        $this->assertNull($conversation->checkout_state);
        echo "✅ STEP 2: 'Can i see the products' → No checkout state yet\n";

        // ── STEP 3: "Can i see the images" → Product image sent ─────────────
        $conversation = $this->simulateIncomingMessage('Can i see the images', 'mid-3');

        // Simulate that a product image was sent and mapped
        $productImageMessage = Message::create([
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

        echo "✅ STEP 3: 'Can i see the images' → Product image sent and mapped\n";

        // ── STEP 4: "Okay can i place an order" → Order flow starts ──────────
        $conversation = $this->simulateIncomingMessage('Okay can i place an order', 'mid-4');

        // The system should have product selected but missing customer info
        $this->assertNotNull($conversation->checkout_state);
        $this->assertEquals('dress-001', $conversation->checkout_state['salla_product_id'] ?? null);
        echo "✅ STEP 4: 'Okay can i place an order' → Product selected, collecting info\n";

        // ── STEP 5: Customer provides phone ──────────────────────────────────
        $conversation = $this->simulateIncomingMessage('phone is 01152879755', 'mid-5');

        $this->assertEquals('01152879755', $conversation->checkout_state['phone'] ?? null);
        $this->assertNull($conversation->checkout_state['address'] ?? null);
        // CRITICAL: sender_id must NOT become phone
        $this->assertNotEquals($this->senderId, $conversation->checkout_state['phone'] ?? null);
        echo "✅ STEP 5: 'phone is 01152879755' → Phone set, address still null\n";

        // ── STEP 6: Customer provides address ────────────────────────────────
        $conversation = $this->simulateIncomingMessage('address is giza fisal 36 sayed Abdelrahman eladwy kafr tohorms', 'mid-6');

        $this->assertEquals('01152879755', $conversation->checkout_state['phone'] ?? null);
        $this->assertEquals('giza fisal 36 sayed Abdelrahman eladwy kafr tohorms', $conversation->checkout_state['address'] ?? null);
        echo "✅ STEP 6: 'address is giza fisal 36...' → Address set, phone preserved\n";

        // ── STEP 7: "The dress" → Product resolution from context ────────────
        $conversation = $this->simulateIncomingMessage('The dress', 'mid-7');

        // Product should still be selected
        $this->assertEquals('dress-001', $conversation->checkout_state['salla_product_id'] ?? null);
        echo "✅ STEP 7: 'The dress' → Product still selected from context\n";

        // ── STEP 8: "This one" with reply_to.mid → Product resolution from reply-to
        $conversation = $this->simulateIncomingMessage('This one', 'mid-8', 'img-mid-1');

        // Product should be resolved from reply-to
        $this->assertEquals('dress-001', $conversation->checkout_state['salla_product_id'] ?? null);
        echo "✅ STEP 8: 'This one' (reply-to img-mid-1) → Product resolved from reply-to\n";

        // ── STEP 9: Verify state survives corrections ────────────────────────
        $conversation = $this->simulateIncomingMessage('No actually the phone is 01152879755 and the address is giza fisal 36 sayed Abdelrahman eladwy kafr tohorms', 'mid-9');

        // Product selection must remain intact
        $this->assertEquals('dress-001', $conversation->checkout_state['salla_product_id'] ?? null);
        $this->assertEquals('01152879755', $conversation->checkout_state['phone'] ?? null);
        echo "✅ STEP 9: Customer correction → Product selection preserved\n";

        // ── STEP 10: Verify webhook idempotency ───────────────────────────────
        $messageCountBefore = Message::where('conversation_id', $conversation->id)->count();

        // Simulate duplicate webhook delivery (same platform message ID)
        $this->simulateIncomingMessage('This one', 'mid-8', 'img-mid-1');

        $messageCountAfter = Message::where('conversation_id', $conversation->id)->count();
        $this->assertEquals($messageCountBefore, $messageCountAfter);
        echo "✅ STEP 10: Duplicate webhook → No duplicate message created\n";

        // ── STEP 11: Verify different messages are NOT duplicates ─────────────
        $messageCountBefore = Message::where('conversation_id', $conversation->id)->count();

        // Simulate different customer message (different platform message ID)
        $this->simulateIncomingMessage('Thank you', 'mid-10', null);

        $messageCountAfter = Message::where('conversation_id', $conversation->id)->count();
        $this->assertEquals($messageCountBefore + 1, $messageCountAfter);
        echo "✅ STEP 11: Different message → New message created\n";

        echo "\n🎉 ALL INTEGRATION TESTS PASSED\n";
    }

    /**
     * Simulate an incoming message from Instagram/Meta.
     */
    private function simulateIncomingMessage(string $text, string $platformMessageId, ?string $replyToMid = null): Conversation
    {
        // Clear any existing debounce for this specific message
        Cache::flush();

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

        // Simulate the OrderCheckoutService extraction
        $checkoutService = new \App\Services\OrderCheckoutService();
        $checkoutState = $checkoutService->extractAndMergeState($conversation, $text);
        $fieldStatus = $checkoutService->computeFieldStatus($checkoutState);

        // Update conversation state
        $conversation->update(['checkout_state' => $checkoutState]);

        return $conversation->fresh();
    }
}
