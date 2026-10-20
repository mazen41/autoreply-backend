<?php

namespace Tests\Feature;

use App\Models\BusinessProfile;
use App\Models\Bot;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\CsatRating;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CsatAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_rating_period_returns_null_score_instead_of_zero_percent(): void
    {
        $user = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $user->id]);
        Sanctum::actingAs($user);

        $this->getJson("/api/businesses/{$business->id}/analytics/csat?days=30")
            ->assertOk()
            ->assertJsonPath('csat_score', null)
            ->assertJsonPath('total_ratings', 0)
            ->assertJsonPath('positive_ratings', 0)
            ->assertJsonPath('negative_ratings', 0);
    }

    public function test_rating_percentage_is_derived_from_real_business_ratings(): void
    {
        $user = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $user->id]);
        $channel = Channel::factory()->create(['user_id' => $user->id, 'business_id' => $business->id]);
        $bot = Bot::create(['business_profile_id' => $business->id, 'name' => 'Sales Bot', 'status' => 'active']);
        $conversation = Conversation::factory()->create([
            'business_id' => $business->id,
            'channel_id' => $channel->id,
            'bot_id' => $bot->id,
        ]);
        CsatRating::create([
            'business_id' => $business->id,
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
            'rating' => 'positive',
            'rated_at' => now(),
        ]);
        $secondConversation = Conversation::factory()->create(['business_id' => $business->id]);
        CsatRating::create([
            'business_id' => $business->id,
            'conversation_id' => $secondConversation->id,
            'user_id' => $user->id,
            'rating' => 'negative',
            'rated_at' => now(),
        ]);
        Sanctum::actingAs($user);

        $this->getJson("/api/businesses/{$business->id}/analytics/csat?days=30")
            ->assertOk()
            ->assertJsonPath('csat_score', 50)
            ->assertJsonPath('total_ratings', 2)
            ->assertJsonPath('positive_ratings', 1)
            ->assertJsonPath('negative_ratings', 1);

        $this->getJson("/api/businesses/{$business->id}/analytics/csat?days=30&channel_id={$channel->id}&bot_id={$bot->id}")
            ->assertOk()
            ->assertJsonPath('csat_score', 100)
            ->assertJsonPath('total_ratings', 1);
    }
}
