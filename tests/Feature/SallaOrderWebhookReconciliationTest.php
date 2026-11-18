<?php

namespace Tests\Feature;

use App\Jobs\SallaWebhookJob;
use App\Models\BusinessProfile;
use App\Models\Channel;
use App\Models\CommerceOrderAttempt;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class SallaOrderWebhookReconciliationTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_created_webhook_reconciles_one_matching_attempt_and_duplicate_delivery_is_idempotent(): void
    {
        Cache::flush();
        $user = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $user->id]);
        $store = Channel::factory()->create([
            'user_id' => $user->id,
            'business_id' => $business->id,
            'type' => 'salla',
            'status' => 'connected',
            'page_id' => 'merchant-77',
        ]);
        $messaging = Channel::factory()->create([
            'user_id' => $user->id,
            'business_id' => $business->id,
            'type' => 'facebook',
            'status' => 'connected',
        ]);
        $conversation = Conversation::factory()->create([
            'business_id' => $business->id,
            'channel_id' => $messaging->id,
            'sender_id' => '+966501234567',
            'checkout_state' => [
                'confirmation_state' => 'confirmed',
                'phone' => '+966501234567',
                'salla_product_id' => '44563344',
                'address' => 'Riyadh, Saudi Arabia',
            ],
        ]);
        $attempt = CommerceOrderAttempt::create([
            'business_id' => $business->id,
            'conversation_id' => $conversation->id,
            'connection_id' => $store->id,
            'provider' => 'salla',
            'checkout_fingerprint' => str_repeat('a', 64),
            'idempotency_key' => str_repeat('b', 64),
            'status' => 'submitting',
        ]);

        $payload = [
            'merchant' => 'merchant-77',
            'id' => 'salla-order-123',
            'reference_id' => 'ORD-123',
            'created_at' => '2026-11-18T10:00:00Z',
            'customer' => ['mobile' => '+966501234567'],
            'items' => [['product' => ['id' => '44563344']]],
        ];
        (new SallaWebhookJob('order.created', $payload))->handle();
        (new SallaWebhookJob('order.created', $payload))->handle();

        $this->assertDatabaseHas('commerce_order_attempts', [
            'id' => $attempt->id,
            'status' => 'completed',
            'external_order_id' => 'salla-order-123',
            'order_number' => 'ORD-123',
        ]);
        $this->assertSame('salla-order-123', $conversation->fresh()->checkout_state['order_id']);
        $this->assertDatabaseCount('salla_webhook_receipts', 1);
    }

    public function test_ambiguous_webhook_does_not_mark_multiple_attempts_as_completed(): void
    {
        Cache::flush();
        $user = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $user->id]);
        $store = Channel::factory()->create([
            'user_id' => $user->id, 'business_id' => $business->id,
            'type' => 'salla', 'status' => 'connected', 'page_id' => 'merchant-78',
        ]);
        $messaging = Channel::factory()->create([
            'user_id' => $user->id, 'business_id' => $business->id,
            'type' => 'facebook', 'status' => 'connected',
        ]);

        foreach (['first', 'second'] as $index => $marker) {
            $messaging = Channel::factory()->create([
                'user_id' => $user->id, 'business_id' => $business->id,
                'type' => 'facebook', 'status' => 'connected',
            ]);
            $conversation = Conversation::factory()->create([
                'business_id' => $business->id, 'channel_id' => $messaging->id,
                'sender_id' => '+966501234567',
                'checkout_state' => [
                    'confirmation_state' => 'confirmed', 'phone' => '+966501234567',
                    'salla_product_id' => '44563344', 'address' => 'Riyadh, Saudi Arabia',
                ],
            ]);
            CommerceOrderAttempt::create([
                'business_id' => $business->id, 'conversation_id' => $conversation->id,
                'connection_id' => $store->id, 'provider' => 'salla',
                'checkout_fingerprint' => str_repeat((string) ($index + 1), 64),
                'idempotency_key' => str_repeat((string) ($index + 3), 64),
                'status' => 'unknown',
            ]);
        }

        (new SallaWebhookJob('order.created', [
            'merchant' => 'merchant-78', 'id' => 'salla-order-456',
            'created_at' => '2026-11-18T11:00:00Z',
            'customer' => ['mobile' => '+966501234567'],
            'items' => [['product' => ['id' => '44563344']]],
        ]))->handle();

        $this->assertSame(0, CommerceOrderAttempt::query()->where('status', 'completed')->count());
        $this->assertSame(2, CommerceOrderAttempt::query()->where('status', 'unknown')->count());
    }
}
