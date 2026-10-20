<?php

namespace Tests\Feature;

use App\Models\BusinessProfile;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CsatTenantAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_business_owner_can_submit_and_read_a_rating_for_its_conversation(): void
    {
        $owner = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $owner->id]);
        $conversation = Conversation::factory()->create(['business_id' => $business->id]);

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/conversations/{$conversation->id}/csat", ['rating' => 'positive'])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->actingAs($owner, 'sanctum')
            ->getJson("/api/conversations/{$conversation->id}/csat")
            ->assertOk()
            ->assertJsonPath('rating', 'positive');
    }

    public function test_user_cannot_submit_or_read_csat_for_another_business_conversation(): void
    {
        $owner = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $owner->id]);
        $foreignBusiness = BusinessProfile::factory()->create();
        $foreignConversation = Conversation::factory()->create(['business_id' => $foreignBusiness->id]);

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/conversations/{$foreignConversation->id}/csat", ['rating' => 'negative'])
            ->assertNotFound();

        $this->actingAs($owner, 'sanctum')
            ->getJson("/api/conversations/{$foreignConversation->id}/csat")
            ->assertNotFound();

        $this->assertDatabaseMissing('csat_ratings', [
            'business_id' => $foreignBusiness->id,
            'conversation_id' => $foreignConversation->id,
        ]);
    }

    public function test_team_member_needs_conversation_write_permission_to_submit_rating(): void
    {
        $owner = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $owner->id]);
        $conversation = Conversation::factory()->create(['business_id' => $business->id]);
        $viewer = User::factory()->create();
        \App\Models\TeamMember::factory()->create([
            'business_id' => $business->id,
            'user_id' => $viewer->id,
            'role' => 'viewer',
            'is_active' => true,
        ]);

        $this->actingAs($viewer, 'sanctum')
            ->postJson("/api/conversations/{$conversation->id}/csat", ['rating' => 'negative'])
            ->assertNotFound();

        $this->assertDatabaseMissing('csat_ratings', ['conversation_id' => $conversation->id]);
    }
}
