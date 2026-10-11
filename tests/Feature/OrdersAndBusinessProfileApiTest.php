<?php

namespace Tests\Feature;

use App\Models\BusinessProfile;
use App\Models\Channel;
use App\Models\CommerceOrder;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Services\ChannelMessageService;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrdersAndBusinessProfileApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_orders_are_tenant_scoped_and_return_the_connected_store_and_first_item(): void
    {
        [$user, $business, $channel] = $this->makeTenant('shopify');
        [, $otherBusiness, $otherChannel] = $this->makeTenant('salla');
        CommerceOrder::create([
            'business_id' => $business->id,
            'channel_id' => $channel->id,
            'external_id' => 'external-1',
            'order_number' => '1001',
            'status' => 'processing',
            'total' => 25,
            'currency' => 'EGP',
            'customer_name' => 'Mazen',
            'line_items' => [['name' => 'Blue shirt', 'quantity' => 1]],
            'ordered_at' => now(),
        ]);
        CommerceOrder::create([
            'business_id' => $otherBusiness->id,
            'channel_id' => $otherChannel->id,
            'external_id' => 'external-2',
            'order_number' => '2002',
            'status' => 'processing',
            'total' => 50,
            'currency' => 'SAR',
            'ordered_at' => now(),
        ]);
        Sanctum::actingAs($user);

        $this->withHeader('X-Business-Id', (string) $business->id)
            ->getJson('/api/orders?store_type=shopify')
            ->assertOk()
            ->assertJsonPath('data.0.order_id', '1001')
            ->assertJsonPath('data.0.product_name', 'Blue shirt')
            ->assertJsonPath('data.0.store_type', 'shopify')
            ->assertJsonPath('total', 1);
    }

    public function test_business_settings_read_and_write_only_selected_business_profile_fields(): void
    {
        [$user, $business] = $this->makeTenant('instagram');
        Sanctum::actingAs($user);

        $this->withHeader('X-Business-Id', (string) $business->id)
            ->postJson('/api/business', [
                'business_name' => 'Example Store',
                'description' => 'Regional clothing shop',
                'website' => 'https://example.test',
                'address' => 'Main Street',
                'reply_style' => 'friendly',
            ])
            ->assertOk()
            ->assertJsonPath('business_name', 'Example Store')
            ->assertJsonPath('website', 'https://example.test');

        $this->withHeader('X-Business-Id', (string) $business->id)
            ->getJson('/api/business')
            ->assertOk()
            ->assertJsonPath('description', 'Regional clothing shop')
            ->assertJsonPath('address', 'Main Street');
    }

    public function test_tiktok_cannot_be_connected_or_enabled_for_automated_replies(): void
    {
        [$user, $business, $channel] = $this->makeTenant('tiktok');
        Sanctum::actingAs($user);

        $this->withHeader('X-Business-Id', (string) $business->id)
            ->postJson('/api/channels/connect/tiktok')
            ->assertStatus(410);

        $this->withHeader('X-Business-Id', (string) $business->id)
            ->patchJson("/api/channels/{$channel->id}", ['ai_enabled' => true])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'TikTok messaging is unsupported; automated replies cannot be enabled for this channel.');

        $conversation = Conversation::factory()->create([
            'business_id' => $business->id,
            'channel_id' => $channel->id,
        ]);
        $message = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'direction' => 'outbound',
            'status' => 'pending',
        ]);
        $this->assertFalse(app(ChannelMessageService::class)->sendMessage($channel, $conversation, $message));
        $this->assertSame('unsupported_channel_type', $message->fresh()->error_details);
    }

    public function test_message_media_requires_authorization_header_and_selected_business(): void
    {
        [$user, $business, $channel] = $this->makeTenant('instagram');
        $conversation = Conversation::factory()->create([
            'business_id' => $business->id,
            'channel_id' => $channel->id,
        ]);
        $message = Message::factory()->create(['conversation_id' => $conversation->id]);

        $this->getJson("/api/messages/{$message->id}/media?token=untrusted")
            ->assertUnauthorized();

        Sanctum::actingAs($user);
        $this->withHeader('X-Business-Id', (string) $business->id)
            ->getJson("/api/messages/{$message->id}/media")
            ->assertNotFound();
    }

    private function makeTenant(string $channelType): array
    {
        $user = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $user->id]);
        $channel = Channel::factory()->create([
            'user_id' => $user->id,
            'business_id' => $business->id,
            'type' => $channelType,
            'store_name' => 'Test store',
        ]);

        return [$user, $business, $channel];
    }
}
