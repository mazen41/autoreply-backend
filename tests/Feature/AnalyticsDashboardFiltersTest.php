<?php

namespace Tests\Feature;

use App\Models\Bot;
use App\Models\BusinessProfile;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AnalyticsDashboardFiltersTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_filters_metrics_by_business_channel_and_bot(): void
    {
        $owner = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $owner->id]);
        $channel = Channel::factory()->create(['business_id' => $business->id]);
        $otherChannel = Channel::factory()->create(['business_id' => $business->id]);
        $bot = Bot::create(['business_profile_id' => $business->id, 'name' => 'Sales', 'status' => 'active']);
        Conversation::factory()->create([
            'business_id' => $business->id,
            'channel_id' => $channel->id,
            'bot_id' => $bot->id,
        ]);
        Conversation::factory()->create([
            'business_id' => $business->id,
            'channel_id' => $otherChannel->id,
        ]);
        Sanctum::actingAs($owner);

        $this->getJson("/api/businesses/{$business->id}/analytics/dashboard?channel_id={$channel->id}&bot_id={$bot->id}")
            ->assertOk()
            ->assertJsonPath('conversations.total', 1)
            ->assertJsonPath('conversations.open', 1)
            ->assertJsonPath('filters.channel_id', $channel->id)
            ->assertJsonPath('filters.bot_id', $bot->id);
    }

    public function test_dashboard_rejects_account_and_bot_filters_from_another_business(): void
    {
        $owner = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $owner->id]);
        $otherBusiness = BusinessProfile::factory()->create();
        $otherChannel = Channel::factory()->create(['business_id' => $otherBusiness->id]);
        $otherBot = Bot::create(['business_profile_id' => $otherBusiness->id, 'name' => 'Other', 'status' => 'active']);
        Sanctum::actingAs($owner);

        $this->getJson("/api/businesses/{$business->id}/analytics/dashboard?channel_id={$otherChannel->id}")->assertUnprocessable();
        $this->getJson("/api/businesses/{$business->id}/analytics/dashboard?bot_id={$otherBot->id}")->assertUnprocessable();
    }

    public function test_home_dashboard_report_endpoints_apply_channel_and_bot_filters(): void
    {
        $owner = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $owner->id]);
        $channel = Channel::factory()->create(['business_id' => $business->id]);
        $otherChannel = Channel::factory()->create(['business_id' => $business->id]);
        $bot = Bot::create(['business_profile_id' => $business->id, 'name' => 'Selected Bot', 'status' => 'active']);
        $selectedConversation = Conversation::factory()->create([
            'business_id' => $business->id, 'channel_id' => $channel->id, 'bot_id' => $bot->id,
        ]);
        $otherConversation = Conversation::factory()->create([
            'business_id' => $business->id, 'channel_id' => $otherChannel->id,
        ]);
        Message::create([
            'conversation_id' => $selectedConversation->id, 'content' => 'Selected reply', 'direction' => 'outbound',
            'status' => 'sent', 'send_status' => 'sent', 'is_ai' => true, 'created_at' => now(),
        ]);
        Message::create([
            'conversation_id' => $otherConversation->id, 'content' => 'Unrelated reply', 'direction' => 'outbound',
            'status' => 'sent', 'send_status' => 'sent', 'is_ai' => false, 'created_at' => now(),
        ]);
        Sanctum::actingAs($owner);
        $filters = "days=7&channel_id={$channel->id}&bot_id={$bot->id}";
        $headers = ['X-Business-Id' => (string) $business->id];

        $this->getJson("/api/stats?{$filters}", $headers)->assertOk()->assertJsonPath('total_messages', 1);
        $this->getJson("/api/reports/daily-messages?{$filters}", $headers)->assertOk()->assertJsonPath('total', 1);
        $this->getJson("/api/reports/ai-performance?{$filters}", $headers)
            ->assertOk()->assertJsonPath('total_messages', 1)->assertJsonPath('auto_replies', 1);
    }

    public function test_home_dashboard_report_filters_reject_another_business_account(): void
    {
        $owner = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $owner->id]);
        $otherBusiness = BusinessProfile::factory()->create();
        $otherChannel = Channel::factory()->create(['business_id' => $otherBusiness->id]);
        $otherBot = Bot::create(['business_profile_id' => $otherBusiness->id, 'name' => 'Other Bot', 'status' => 'active']);
        Sanctum::actingAs($owner);
        $headers = ['X-Business-Id' => (string) $business->id];

        $this->getJson("/api/stats?channel_id={$otherChannel->id}", $headers)->assertUnprocessable();
        $this->getJson("/api/reports/ai-performance?bot_id={$otherBot->id}", $headers)->assertUnprocessable();
    }
}
