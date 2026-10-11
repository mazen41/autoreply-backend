<?php

namespace Tests\Feature;

use App\Models\BusinessProfile;
use App\Models\Channel;
use App\Models\Bot;
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

        $response = $this->withHeader('X-Business-Id', (string) $business->id)->postJson('/api/bots', [
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

        $this->withHeader('X-Business-Id', (string) $business->id)->postJson('/api/bots', [
            'name' => 'Bot',
            'ecommerce_connection_ids' => [$foreignStore->id],
            'default_ecommerce_connection_id' => $foreignStore->id,
        ])->assertUnprocessable();
    }

    public function test_store_channel_id_is_rejected_from_bot_channel_assignments(): void
    {
        [$user, $business] = $this->businessOwner('Store channel validation');
        $store = $this->commerceChannel($user, $business, 'shopify', 'Not a communication channel');
        Sanctum::actingAs($user);

        $this->withHeader('X-Business-Id', (string) $business->id)->postJson('/api/bots', [
            'name' => 'Bot',
            'channel_ids' => [$store->id],
        ])->assertUnprocessable()->assertJsonValidationErrors('channel_ids.0');
    }

    public function test_store_channel_id_is_rejected_as_primary_responder(): void
    {
        [$user, $business] = $this->businessOwner('Primary store validation');
        $store = $this->commerceChannel($user, $business, 'salla', 'Store cannot be primary');
        Sanctum::actingAs($user);

        $this->withHeader('X-Business-Id', (string) $business->id)->postJson('/api/bots', [
            'name' => 'Bot',
            'channel_ids' => [],
            'primary_channel_ids' => [$store->id],
        ])->assertUnprocessable()->assertJsonValidationErrors('primary_channel_ids.0');
    }

    public function test_default_store_must_be_in_selected_commerce_connections(): void
    {
        [$user, $business] = $this->businessOwner('Default store validation');
        $selectedStore = $this->commerceChannel($user, $business, 'shopify', 'Selected Store');
        $unselectedStore = $this->commerceChannel($user, $business, 'woocommerce', 'Unselected Store');
        Sanctum::actingAs($user);

        $this->withHeader('X-Business-Id', (string) $business->id)->postJson('/api/bots', [
            'name' => 'Bot',
            'ecommerce_connection_ids' => [$selectedStore->id],
            'default_ecommerce_connection_id' => $unselectedStore->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('default_ecommerce_connection_id');
    }

    public function test_update_and_attach_endpoints_reject_commerce_stores_as_channels(): void
    {
        [$user, $business] = $this->businessOwner('Store channel update validation');
        $store = $this->commerceChannel($user, $business, 'woocommerce', 'Store');
        $bot = Bot::create([
            'business_profile_id' => $business->id,
            'name' => 'Existing Bot',
            'status' => 'active',
        ]);
        Sanctum::actingAs($user);
        $headers = ['X-Business-Id' => (string) $business->id];

        $this->withHeaders($headers)->putJson("/api/bots/{$bot->id}", [
            'channel_ids' => [$store->id],
        ])->assertUnprocessable()->assertJsonValidationErrors('channel_ids.0');

        $this->withHeaders($headers)->postJson("/api/bots/{$bot->id}/channels", [
            'channel_ids' => [$store->id],
        ])->assertUnprocessable()->assertJsonValidationErrors('channel_ids.0');
    }

    public function test_migration_removes_only_store_rows_from_bot_channel_assignments(): void
    {
        [$user, $business] = $this->businessOwner('Legacy store pivot migration');
        $store = $this->commerceChannel($user, $business, 'salla', 'Legacy Store');
        $facebook = $this->communicationChannel($user, $business, 'facebook', 'Facebook Page');
        $bot = Bot::create([
            'business_profile_id' => $business->id,
            'name' => 'Legacy Bot',
            'status' => 'active',
        ]);
        $bot->channels()->attach([$store->id, $facebook->id]);

        $migration = require database_path('migrations/2026_10_11_000001_remove_store_channels_from_bot_channel_assignments.php');
        $migration->up();

        $this->assertDatabaseMissing('bot_channels', ['bot_id' => $bot->id, 'channel_id' => $store->id]);
        $this->assertDatabaseHas('bot_channels', ['bot_id' => $bot->id, 'channel_id' => $facebook->id]);
    }

    public function test_channel_listing_exposes_only_business_bot_assignments_for_primary_mapping_ui(): void
    {
        [$user, $business] = $this->businessOwner('Channel primary responder list');
        $channel = $this->communicationChannel($user, $business, 'instagram', 'Instagram Account');
        $bot = Bot::create(['business_profile_id' => $business->id, 'name' => 'Active agent', 'status' => 'active']);
        $inactiveBot = Bot::create(['business_profile_id' => $business->id, 'name' => 'Paused agent', 'status' => 'inactive']);
        $bot->channels()->attach($channel->id, ['is_primary' => true]);
        $inactiveBot->channels()->attach($channel->id, ['is_primary' => false]);
        Sanctum::actingAs($user);

        $this->withHeader('X-Business-Id', (string) $business->id)->getJson('/api/channels')
            ->assertOk()
            ->assertJsonPath('data.0.bots.0.id', $bot->id)
            ->assertJsonPath('data.0.bots.0.pivot.is_primary', true)
            ->assertJsonCount(1, 'data.0.bots');
    }

    private function businessOwner(string $name): array
    {
        $user = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $user->id, 'name' => $name]);
        $user->forceFill(['business_id' => $business->id])->save();

        return [$user, $business];
    }

    private function communicationChannel(User $user, BusinessProfile $business, string $type, string $name): Channel
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
