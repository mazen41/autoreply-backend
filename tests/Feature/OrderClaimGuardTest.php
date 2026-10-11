<?php

namespace Tests\Feature;

use App\Models\BusinessProfile;
use App\Models\Channel;
use App\Models\CommerceOrderAttempt;
use App\Models\Conversation;
use App\Models\User;
use App\Services\OrderClaimGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderClaimGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_placed_claim_is_blocked_without_matching_completed_attempt(): void
    {
        [$conversation, $connection] = $this->makeConversation();
        $state = [
            'commerce_channel_id' => $connection->id,
            'commerce_external_id' => 'product-1',
            'phone' => '+966501234567',
            'address' => 'Riyadh',
            'confirmation_summary_sent_at' => now()->subMinute()->toIso8601String(),
        ];

        $result = app(OrderClaimGuard::class)->inspect(
            $conversation,
            "I've placed your order",
            $state,
            ['phone']
        );

        $this->assertFalse($result['allowed']);
        $this->assertSame('missing_phone', $result['reason']);
        $this->assertStringContainsString('phone number', strtolower($result['replacement']));
    }

    public function test_order_being_processed_claim_is_blocked_without_a_completed_order(): void
    {
        [$conversation] = $this->makeConversation();

        $result = app(OrderClaimGuard::class)->inspect(
            $conversation,
            'We have your full name, phone, and address. Your order for Sample Product (600 SAR) is now being processed and you will receive a confirmation shortly.',
            ['full_name' => 'Alex Customer'],
            ['phone', 'address']
        );

        $this->assertFalse($result['allowed']);
        $this->assertTrue($result['claims_completion']);
        $this->assertSame('missing_phone', $result['reason']);
        $this->assertStringContainsString('phone number', strtolower($result['replacement']));
        $this->assertStringContainsString('delivery address', strtolower($result['replacement']));
    }

    public function test_matching_completed_attempt_allows_claim_but_wrong_fingerprint_does_not(): void
    {
        [$conversation, $connection] = $this->makeConversation();
        $state = [
            'commerce_channel_id' => $connection->id,
            'commerce_external_id' => 'product-1',
            'phone' => '+966501234567',
            'address' => 'Riyadh',
            'confirmation_summary_sent_at' => now()->subMinute()->toIso8601String(),
        ];
        $fingerprint = hash('sha256', implode('|', [
            (string) $connection->id,
            'product-1',
            '966501234567',
            'riyadh',
        ]));
        $attempt = [
            'business_id' => $conversation->business_id,
            'conversation_id' => $conversation->id,
            'connection_id' => $connection->id,
            'provider' => 'shopify',
            'checkout_fingerprint' => $fingerprint,
            'idempotency_key' => hash('sha256', uniqid('', true)),
            'status' => 'completed',
            'external_order_id' => 'order-123',
            'order_number' => '#1001',
            'created_at' => now(),
            'updated_at' => now(),
        ];
        CommerceOrderAttempt::create($attempt);

        $allowed = app(OrderClaimGuard::class)->inspect($conversation, 'Order placed successfully', $state, []);
        $this->assertTrue($allowed['allowed']);
        $this->assertTrue($allowed['order_record_present']);

        CommerceOrderAttempt::query()->update(['checkout_fingerprint' => str_repeat('a', 64)]);
        $blocked = app(OrderClaimGuard::class)->inspect($conversation, 'Order placed successfully', $state, []);
        $this->assertFalse($blocked['allowed']);
        $this->assertSame('no_matching_completed_order', $blocked['reason']);
    }

    public function test_egyptian_arabic_order_claim_is_recognized(): void
    {
        [$conversation] = $this->makeConversation();

        $result = app(OrderClaimGuard::class)->inspect($conversation, 'تم تسجيل طلبك بنجاح', [], ['full_name']);

        $this->assertFalse($result['allowed']);
        $this->assertSame('missing_full_name', $result['reason']);
    }

    public function test_reply_cannot_ask_again_for_a_name_that_is_already_in_checkout_state(): void
    {
        [$conversation] = $this->makeConversation();

        $result = app(OrderClaimGuard::class)->inspect(
            $conversation,
            'Could you please provide your full name and phone number?',
            ['full_name' => 'Mazen Hossny Ahmed'],
            ['phone']
        );

        $this->assertFalse($result['allowed']);
        $this->assertSame('asks_known_full_name', $result['reason']);
        $this->assertStringContainsString('phone number', strtolower($result['replacement']));
        $this->assertStringNotContainsString('full name', strtolower($result['replacement']));
    }

    private function makeConversation(): array
    {
        $user = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $user->id]);
        $connection = Channel::factory()->create([
            'business_id' => $business->id,
            'type' => 'shopify',
            'status' => 'connected',
        ]);
        $channel = Channel::factory()->create(['business_id' => $business->id, 'type' => 'instagram']);
        $conversation = Conversation::factory()->create([
            'business_id' => $business->id,
            'channel_id' => $channel->id,
            'ecommerce_connection_id' => $connection->id,
        ]);

        return [$conversation, $connection];
    }
}
