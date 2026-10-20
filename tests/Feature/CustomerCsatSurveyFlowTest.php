<?php

namespace Tests\Feature;

use App\Models\BusinessProfile;
use App\Models\Conversation;
use App\Models\CsatRating;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerCsatSurveyFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_generates_a_limited_link_and_customer_response_enters_analytics(): void
    {
        $owner = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $owner->id]);
        $conversation = Conversation::factory()->create(['business_id' => $business->id, 'status' => 'closed']);
        $link = $this->actingAs($owner, 'sanctum')->withHeader('X-Business-Id', (string) $business->id)
            ->postJson("/api/conversations/{$conversation->id}/csat-survey")
            ->assertCreated()->json();
        $token = $link['token'];

        $this->postJson("/api/conversations/{$conversation->id}/csat-survey")
            ->assertStatus(409)
            ->assertJsonPath('message', 'A feedback link is already active for this conversation.');
        $this->assertDatabaseCount('csat_survey_invites', 1);

        $this->getJson("/api/public/csat-surveys/{$token}")->assertOk()->assertJsonPath('active', true);
        $this->postJson("/api/public/csat-surveys/{$token}/response", ['rating' => 'positive', 'feedback' => 'Fast and helpful.'])
            ->assertOk()->assertJsonPath('success', true);
        $this->assertDatabaseHas('csat_ratings', [
            'business_id' => $business->id,
            'conversation_id' => $conversation->id,
            'rating' => 'positive',
            'source' => 'customer_survey',
            'feedback' => 'Fast and helpful.',
        ]);
        $this->postJson("/api/public/csat-surveys/{$token}/response", ['rating' => 'negative'])->assertStatus(410);
    }

    public function test_survey_requires_a_resolved_conversation_and_opt_out_suppresses_future_requests(): void
    {
        $owner = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $owner->id]);
        $open = Conversation::factory()->create(['business_id' => $business->id, 'status' => 'open']);
        $this->actingAs($owner, 'sanctum')->withHeader('X-Business-Id', (string) $business->id)
            ->postJson("/api/conversations/{$open->id}/csat-survey")->assertUnprocessable();

        $resolved = Conversation::factory()->create(['business_id' => $business->id, 'status' => 'closed']);
        $token = $this->postJson("/api/conversations/{$resolved->id}/csat-survey")->assertCreated()->json('token');
        $this->postJson("/api/public/csat-surveys/{$token}/opt-out")->assertOk();
        $this->postJson("/api/conversations/{$resolved->id}/csat-survey")->assertStatus(409);
        $this->assertDatabaseHas('csat_survey_suppressions', ['business_id' => $business->id]);
    }

    public function test_unresolved_cross_business_conversation_cannot_receive_a_survey_link(): void
    {
        $owner = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $owner->id]);
        $foreignBusiness = BusinessProfile::factory()->create();
        $conversation = Conversation::factory()->create(['business_id' => $foreignBusiness->id, 'status' => 'closed']);

        $this->actingAs($owner, 'sanctum')->withHeader('X-Business-Id', (string) $business->id)
            ->postJson("/api/conversations/{$conversation->id}/csat-survey")->assertNotFound();
        $this->assertDatabaseCount('csat_survey_invites', 0);
    }
}
