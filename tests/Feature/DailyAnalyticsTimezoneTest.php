<?php

namespace Tests\Feature;

use App\Models\AnalyticsDaily;
use App\Models\BusinessProfile;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\AnalyticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DailyAnalyticsTimezoneTest extends TestCase
{
    use RefreshDatabase;

    public function test_response_time_uses_sql_median_of_customer_message_to_first_reply(): void
    {
        $user = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $user->id, 'timezone' => 'UTC']);
        $channel = Channel::factory()->create(['user_id' => $user->id, 'business_id' => $business->id]);
        $conversation = Conversation::factory()->create([
            'business_id' => $business->id,
            'channel_id' => $channel->id,
            'created_at' => Carbon::parse('2026-10-09 00:00:00', 'UTC'),
        ]);

        foreach ([
            ['2026-10-09 01:00:00', 'inbound', false],
            ['2026-10-09 01:00:10', 'outbound', true],
            ['2026-10-09 01:05:00', 'inbound', false],
            ['2026-10-09 01:06:40', 'outbound', false],
            ['2026-10-09 01:10:00', 'inbound', false],
            ['2026-10-09 01:10:20', 'outbound', true],
        ] as [$timestamp, $direction, $isAi]) {
            Message::factory()->create([
                'conversation_id' => $conversation->id,
                'direction' => $direction,
                'is_ai' => $isAi,
                'created_at' => Carbon::parse($timestamp, 'UTC'),
                'updated_at' => Carbon::parse($timestamp, 'UTC'),
            ]);
        }

        $median = app(AnalyticsService::class)->medianResponseTimeSeconds(
            $business->id,
            Carbon::parse('2026-10-09 00:00:00', 'UTC'),
            Carbon::parse('2026-10-09 23:59:59', 'UTC')
        );

        $this->assertEqualsWithDelta(20.0, $median, 0.1);
    }

    public function test_daily_analytics_uses_business_day_boundaries_and_scopes_messages_by_business(): void
    {
        $userA = User::factory()->create();
        $businessA = BusinessProfile::factory()->create(['user_id' => $userA->id, 'timezone' => 'Africa/Cairo']);
        $channelA = Channel::factory()->create(['user_id' => $userA->id, 'business_id' => $businessA->id]);
        $conversationA = Conversation::factory()->create([
            'business_id' => $businessA->id,
            'channel_id' => $channelA->id,
            'created_at' => Carbon::parse('2026-10-08 22:30:00', 'UTC'),
            'last_message_at' => Carbon::parse('2026-10-08 22:30:00', 'UTC'),
        ]);
        Message::factory()->create([
            'conversation_id' => $conversationA->id,
            'direction' => 'inbound',
            'is_ai' => false,
            'created_at' => Carbon::parse('2026-10-08 22:30:00', 'UTC'),
            'updated_at' => Carbon::parse('2026-10-08 22:30:00', 'UTC'),
        ]);
        Message::factory()->create([
            'conversation_id' => $conversationA->id,
            'direction' => 'inbound',
            'is_ai' => false,
            'created_at' => Carbon::parse('2026-10-09 21:30:00', 'UTC'),
            'updated_at' => Carbon::parse('2026-10-09 21:30:00', 'UTC'),
        ]);

        $userB = User::factory()->create();
        $businessB = BusinessProfile::factory()->create(['user_id' => $userB->id, 'timezone' => 'UTC']);
        $channelB = Channel::factory()->create(['user_id' => $userB->id, 'business_id' => $businessB->id]);
        $conversationB = Conversation::factory()->create([
            'business_id' => $businessB->id,
            'channel_id' => $channelB->id,
            'created_at' => Carbon::parse('2026-10-08 22:30:00', 'UTC'),
        ]);
        Message::factory()->create([
            'conversation_id' => $conversationB->id,
            'direction' => 'inbound',
            'created_at' => Carbon::parse('2026-10-08 22:30:00', 'UTC'),
            'updated_at' => Carbon::parse('2026-10-08 22:30:00', 'UTC'),
        ]);

        app(AnalyticsService::class)->calculateDailyAnalytics($businessA->id, '2026-10-09');
        app(AnalyticsService::class)->calculateDailyAnalytics($businessA->id, '2026-10-09');

        $daily = AnalyticsDaily::where('business_id', $businessA->id)->whereDate('date', '2026-10-09')->firstOrFail();
        $this->assertSame(1, $daily->total_conversations);
        $this->assertSame(1, $daily->total_messages);
        $this->assertSame(1, AnalyticsDaily::where('business_id', $businessA->id)->count(), 'Recalculation must update the same business/day row.');
        $this->assertSame(0, AnalyticsDaily::where('business_id', $businessB->id)->count());
    }
}
