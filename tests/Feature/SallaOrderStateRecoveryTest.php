<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;
use App\Models\User;
use App\Models\BusinessProfile;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\ProductMessageMap;
use App\Jobs\ProcessAutoReply;

class SallaOrderStateRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Suppress expected logs during test to keep output clean
        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('error')->zeroOrMoreTimes();
        Log::shouldReceive('warning')->zeroOrMoreTimes();
    }

    /** @test */
    public function it_persists_checkout_state_across_turns_and_recovers_when_api_fails()
    {
        // 1. Setup Data
        $user = User::factory()->create();
        
        $business = BusinessProfile::factory()->create([
            'user_id' => $user->id,
            'name' => 'NazBiz Clothes',
        ]);
        
        $channel = Channel::factory()->create([
            'user_id' => $user->id,
            'type' => 'salla',
            'status' => 'connected',
            'access_token' => 'valid_token',
        ]);
        $channel->business_id = $business->id;
        $channel->save();

        $conversation = Conversation::factory()->create([
            'channel_id' => $channel->id,
            'business_id' => $business->id,
            'sender_id' => '+201152879755',
            'ai_enabled' => true,
        ]);

        // Create a prior ProductMessageMap indicating a product was previously sent
        $quotedMessageId = 'WHATSAPP_MSG_ID_123';
        ProductMessageMap::create([
            'conversation_id' => $conversation->id,
            'channel_id' => $channel->id,
            'whatsapp_message_id' => $quotedMessageId,
            'salla_product_id' => '523147668',
            'product_name' => 'Fancy Dress',
            'product_price' => 174,
            'currency' => 'SAR',
            'image_url' => 'https://example.com/dress.jpg',
        ]);

        // 2. TURN 1: Customer says "I want this one" (replying to product)
        $msg1 = Message::create([
            'conversation_id' => $conversation->id,
            'content' => 'I want this one',
            'direction' => 'inbound',
            'is_ai' => false,
            'metadata' => ['quoted_message_id' => $quotedMessageId],
        ]);

        // Mock LLM call for Turn 1
        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => json_encode([
                                'success' => true,
                                'reply' => 'Great choice! Can I get your full name and address for delivery?',
                                'intent' => 'place_order',
                                'needs_escalation' => false,
                                'confidence' => 0.99,
                                'escalation_reason' => 'none',
                                'needs_images' => false,
                            ])
                        ]
                    ]
                ]
            ], 200),
            // Mock Salla API returning product details
            'api.salla.dev/admin/v2/products*' => Http::response([
                'data' => [
                    [
                        'id' => 523147668,
                        'name' => 'Fancy Dress',
                        'price' => ['amount' => 174, 'currency' => 'SAR'],
                    ]
                ],
                'pagination' => ['total' => 1, 'count' => 1, 'per_page' => 10, 'current_page' => 1]
            ], 200)
        ]);

        // Run Turn 1
        $job1 = new ProcessAutoReply($msg1->id);
        $job1->handle();

        // Assert checkout_state was populated
        $conversation->refresh();
        $this->assertNotNull($conversation->checkout_state);
        $this->assertEquals('523147668', $conversation->checkout_state['salla_product_id']);
        $this->assertEquals('Fancy Dress', $conversation->checkout_state['product_name']);

        // 3. TURN 2: Customer provides name/address (no quoted message ID)
        $msg2 = Message::create([
            'conversation_id' => $conversation->id,
            'content' => 'Mazen Hossny, 123 Nile St, Cairo. 01152879755',
            'direction' => 'inbound',
            'is_ai' => false,
        ]);

        // Mock LLM call for Turn 2
        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => json_encode([
                                'success' => true,
                                'reply' => 'Thanks Mazen! Shall I confirm this order? ✅',
                                'intent' => 'place_order',
                                'needs_escalation' => false,
                                'confidence' => 0.99,
                                'escalation_reason' => 'none',
                                'needs_images' => false,
                            ])
                        ]
                    ]
                ]
            ], 200),
            // Mock Salla API failing (e.g. timeout) - this is what caused the bug!
            'api.salla.dev/admin/v2/products*' => Http::response('Gateway Timeout', 504)
        ]);

        // Run Turn 2
        $job2 = new ProcessAutoReply($msg2->id);
        $job2->handle();

        // Assert checkout_state persisted the phone number and kept the product
        $conversation->refresh();
        $this->assertNotNull($conversation->checkout_state);
        $this->assertEquals('523147668', $conversation->checkout_state['salla_product_id']); // Did not lose product!
        $this->assertStringContainsString('01152879755', $conversation->checkout_state['phone']); // Extracted phone (normalized)

        // Verify the bot didn't send the generic "I'm sorry I'm having a brief issue" message,
        // but instead used the AI reply which had the context
        $botReply2 = Message::where('conversation_id', $conversation->id)
            ->where('is_ai', true)
            ->orderBy('id', 'desc')
            ->first();
            
        $this->assertStringNotContainsString('having a brief issue loading our product catalogue', $botReply2->content);
        $this->assertStringContainsString('Thanks Mazen', $botReply2->content);
        
        // 4. TURN 3: Customer confirms order
        $msg3 = Message::create([
            'conversation_id' => $conversation->id,
            'content' => 'Yes, please confirm',
            'direction' => 'inbound',
            'is_ai' => false,
        ]);

        // Mock LLM call for Turn 3
        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => json_encode([
                                'success' => true,
                                'reply' => 'Your order has been placed! 🎉',
                                'intent' => 'place_order',
                                'needs_escalation' => false,
                                'confidence' => 0.99,
                                'escalation_reason' => 'none',
                                'needs_images' => false,
                            ])
                        ]
                    ]
                ]
            ], 200),
            // Mock Salla API for order placement
            'api.salla.dev/admin/v2/products*' => Http::response([
                'data' => [
                    [
                        'id' => 523147668,
                        'name' => 'Fancy Dress',
                        'price' => ['amount' => 174, 'currency' => 'SAR'],
                    ]
                ],
                'pagination' => ['total' => 1, 'count' => 1, 'per_page' => 10, 'current_page' => 1]
            ], 200)
        ]);
        
        // Run Turn 3
        $job3 = new ProcessAutoReply($msg3->id);
        $job3->handle();

        // Assert checkout_state contains completed order status with real order ID
        $conversation->refresh();
        $this->assertNotNull($conversation->checkout_state);
        $this->assertEquals('completed', $conversation->checkout_state['status']);
        $this->assertNotEmpty($conversation->checkout_state['order_id']);
    }
}
