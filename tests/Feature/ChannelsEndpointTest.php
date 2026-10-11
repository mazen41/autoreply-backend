<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Bot;
use App\Models\BusinessProfile;
use App\Models\User;
use App\Models\FeatureFlag;
use App\Models\BusinessFeatureFlagAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ChannelsEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_channels_index_returns_authenticated_users_channels(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $user->id]);
        $otherBusiness = BusinessProfile::factory()->create(['user_id' => $otherUser->id]);

        Channel::create([
            'user_id' => $user->id,
            'business_id' => $business->id,
            'type' => 'facebook',
            'page_id' => 'page-1',
            'page_name' => 'Main Page',
            'access_token' => 'secret-token',
            'status' => 'connected',
            'connected_at' => now(),
            'ai_enabled' => true,
        ]);

        Channel::create([
            'user_id' => $otherUser->id,
            'business_id' => $otherBusiness->id,
            'type' => 'gmail',
            'page_id' => 'mailbox-1',
            'page_name' => 'Other Mailbox',
            'access_token' => 'other-secret-token',
            'status' => 'connected',
            'connected_at' => now(),
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/channels', ['X-Business-Id' => (string) $business->id])
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type', 'facebook')
            ->assertJsonPath('data.0.page_name', 'Main Page')
            ->assertJsonMissing(['access_token' => 'secret-token']);
    }

    public function test_channel_and_bot_screens_report_paused_effective_ai_for_inactive_agent(): void
    {
        $user = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $user->id]);
        $channel = Channel::create([
            'user_id' => $user->id,
            'business_id' => $business->id,
            'type' => 'instagram',
            'page_id' => 'paused-agent-page',
            'access_token' => 'test-token',
            'status' => 'connected',
            'ai_enabled' => true,
        ]);
        $bot = Bot::create([
            'business_profile_id' => $business->id,
            'name' => 'Paused agent',
            'status' => 'inactive',
        ]);
        $bot->channels()->attach($channel->id, ['is_primary' => true]);
        $flag = FeatureFlag::where('key', 'phase3_no_agent_fail_closed')->firstOrFail();
        BusinessFeatureFlagAssignment::create([
            'business_id' => $business->id,
            'feature_flag_id' => $flag->id,
            'enabled' => true,
        ]);
        Sanctum::actingAs($user);

        $this->getJson('/api/channels', ['X-Business-Id' => (string) $business->id])
            ->assertOk()
            ->assertJsonPath('data.0.effective_ai_enabled', false)
            ->assertJsonPath('data.0.ai_pause_reason', 'no_active_agent');

        $this->withoutMiddleware(\App\Http\Middleware\PlanEnforcement::class);
        $this->getJson('/api/bots', ['X-Business-Id' => (string) $business->id])
            ->assertOk()
            ->assertJsonPath('bots.0.effective_ai_enabled', false)
            ->assertJsonPath('bots.0.ai_pause_reason', 'agent_inactive')
            ->assertJsonPath('bots.0.channels.0.agent_ai_enabled', false);
    }

    public function test_owner_can_set_channel_order_confirmation_override_or_inherit_business_default(): void
    {
        $user = User::factory()->create();
        $business = BusinessProfile::factory()->create([
            'user_id' => $user->id,
            'auto_order_confirmation_enabled' => true,
        ]);
        $channel = Channel::create([
            'user_id' => $user->id,
            'business_id' => $business->id,
            'type' => 'instagram',
            'page_id' => 'order-toggle-channel',
            'access_token' => 'test-token',
            'status' => 'connected',
        ]);
        Sanctum::actingAs($user);

        $this->patchJson('/api/channels/' . $channel->id, ['auto_order_confirmation_enabled' => false], [
            'X-Business-Id' => (string) $business->id,
        ])->assertOk();
        $this->assertFalse((bool) $channel->fresh()->auto_order_confirmation_enabled);

        $this->patchJson('/api/channels/' . $channel->id, ['auto_order_confirmation_enabled' => null], [
            'X-Business-Id' => (string) $business->id,
        ])->assertOk();
        $this->assertNull($channel->fresh()->auto_order_confirmation_enabled);
    }
}
