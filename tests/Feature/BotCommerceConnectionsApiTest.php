<?php

namespace Tests\Feature;

use App\Models\BusinessProfile;
use App\Models\Channel;
use App\Models\User;
use App\Models\Package;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BotCommerceConnectionsApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Package::create([
            'name' => 'Free',
            'name_ar' => 'Free',
            'price_monthly' => 0,
            'price_yearly' => 0,
            'ai_replies_limit' => -1,
            'is_active' => true,
        ]);
    }

    public function test_bot_can_be_configured_with_multiple_commerce_accounts_and_default(): void
    {
        $user = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $user->id, 'name' => 'Multi-store']);
        $user->forceFill(['business_id' => $business->id])->save();
        $shopA = $this->commerceChannel($user, $business, 'shopify', 'Shopify Egypt');
        $shopB = $this->commerceChannel($user, $business, 'shopify', 'Shopify Saudi');
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/bots', [
            'name' => 'Fashion Bot',
            'status' => 'active',
            'ecommerce_connection_ids' => [$shopA->id, $shopB->id],
            'default_ecommerce_connection_id' => $shopB->id,
        ]);

        $response->assertCreated()
            ->assertJsonPath('bot.ecommerce_channel_id', $shopB->id)
            ->assertJsonCount(2, 'bot.ecommerce_connections');
        $this->assertDatabaseHas('bot_ecommerce_connections', [
            'bot_id' => $response->json('bot.id'),
            'ecommerce_connection_id' => $shopA->id,
            'is_enabled' => true,
            'is_default' => false,
        ]);
        $this->assertDatabaseHas('bot_ecommerce_connections', [
            'bot_id' => $response->json('bot.id'),
            'ecommerce_connection_id' => $shopB->id,
            'is_enabled' => true,
            'is_default' => true,
        ]);
        $this->assertArrayNotHasKey('access_token', $response->json('bot.ecommerce_connections.0'));
    }

    public function test_bot_cannot_be_assigned_a_commerce_connection_from_another_business(): void
    {
        $owner = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $owner->id, 'name' => 'Business A']);
        $owner->forceFill(['business_id' => $business->id])->save();
        $foreignOwner = User::factory()->create();
        $foreignBusiness = BusinessProfile::factory()->create(['user_id' => $foreignOwner->id, 'name' => 'Business B']);
        $foreignStore = $this->commerceChannel($foreignOwner, $foreignBusiness, 'woocommerce', 'Foreign Store');
        Sanctum::actingAs($owner);

        $this->postJson('/api/bots', [
            'name' => 'Bot',
            'ecommerce_connection_ids' => [$foreignStore->id],
            'default_ecommerce_connection_id' => $foreignStore->id,
        ])->assertUnprocessable();
    }

    private function commerceChannel(User $user, BusinessProfile $business, string $type, string $name): Channel
    {
        return Channel::create([
            'user_id' => $user->id,
            'business_id' => $business->id,
            'type' => $type,
            'page_id' => strtolower(str_replace(' ', '-', $name)),
            'page_name' => $name,
            'access_token' => 'test-token',
            'status' => 'connected',
            'ai_enabled' => true,
        ]);
    }
}
