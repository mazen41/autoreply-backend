<?php

namespace Tests\Feature;

use App\Models\BusinessProfile;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DashboardDateRangeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\App\Http\Middleware\PlanEnforcement::class);
    }

    public function test_dashboard_headlines_and_ai_metrics_follow_selected_days(): void
    {
        $owner = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $owner->id, 'timezone' => 'UTC']);
        $channel = Channel::create([
            'user_id' => $owner->id, 'business_id' => $business->id, 'type' => 'instagram',
            'page_id' => 'ig-range-test', 'page_name' => 'Range test', 'access_token' => 'test-token', 'status' => 'connected',
        ]);
        $conversation = Conversation::create([
            'channel_id' => $channel->id, 'business_id' => $business->id, 'sender_id' => 'customer-range-test',
        ]);
        Message::create([
            'conversation_id' => $conversation->id, 'content' => 'recent', 'direction' => 'inbound',
            'is_ai' => false, 'created_at' => now()->subDays(2), 'updated_at' => now()->subDays(2),
        ]);
        Message::create([
            'conversation_id' => $conversation->id, 'content' => 'older', 'direction' => 'outbound',
            'is_ai' => true, 'created_at' => now()->subDays(12), 'updated_at' => now()->subDays(12),
        ]);
        Sanctum::actingAs($owner);

        $short = $this->withHeader('X-Business-Id', (string) $business->id)
            ->getJson('/api/stats?days=7&timezone=UTC')->assertOk()->json();
        $long = $this->withHeader('X-Business-Id', (string) $business->id)
            ->getJson('/api/stats?days=30&timezone=UTC')->assertOk()->json();
        $shortAi = $this->withHeader('X-Business-Id', (string) $business->id)
            ->getJson('/api/reports/ai-performance?days=7&timezone=UTC')->assertOk()->json();
        $longAi = $this->withHeader('X-Business-Id', (string) $business->id)
            ->getJson('/api/reports/ai-performance?days=30&timezone=UTC')->assertOk()->json();

        $this->assertSame(1, $short['total_messages']);
        $this->assertSame(2, $long['total_messages']);
        $this->assertSame(1, $shortAi['total_messages']);
        $this->assertSame(2, $longAi['total_messages']);
        $this->assertSame(7, $short['range_days']);
        $this->assertSame(30, $longAi['range_days']);
    }

    public function test_dashboard_rejects_invalid_range_and_uses_business_timezone(): void
    {
        $owner = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $owner->id, 'timezone' => 'Africa/Cairo']);
        Sanctum::actingAs($owner);
        $this->withHeader('X-Business-Id', (string) $business->id)
            ->getJson('/api/stats?days=999&timezone=UTC')->assertUnprocessable();
        $this->withHeader('X-Business-Id', (string) $business->id)
            ->getJson('/api/stats?days=7&timezone=Invalid/Zone')->assertOk()->assertJsonPath('timezone', 'Africa/Cairo');
    }
}
