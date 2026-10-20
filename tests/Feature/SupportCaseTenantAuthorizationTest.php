<?php

namespace Tests\Feature;

use App\Models\BusinessProfile;
use App\Models\Conversation;
use App\Models\SupportCase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupportCaseTenantAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_business_owner_can_create_update_and_comment_on_a_case_linked_to_its_conversation(): void
    {
        $owner = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $owner->id]);
        $conversation = Conversation::factory()->create(['business_id' => $business->id]);

        $response = $this->actingAs($owner, 'sanctum')
            ->withHeader('X-Business-Id', (string) $business->id)
            ->postJson('/api/cases', [
                'subject' => 'Delivery question',
                'description' => 'Customer asked when the parcel will arrive.',
                'conversation_id' => $conversation->id,
                'priority' => 'high',
            ])
            ->assertCreated()
            ->assertJsonPath('case.status', 'open')
            ->assertJsonPath('case.priority', 'high')
            ->assertJsonPath('case.conversation.id', $conversation->id);

        $caseId = $response->json('case.id');
        $this->patchJson("/api/cases/{$caseId}", ['status' => 'resolved'])
            ->assertOk()->assertJsonPath('case.status', 'resolved');
        $this->postJson("/api/cases/{$caseId}/comments", ['body' => 'Carrier confirmed delivery.'])
            ->assertCreated()->assertJsonPath('comment.body', 'Carrier confirmed delivery.');
        $this->getJson("/api/cases/{$caseId}")
            ->assertOk()->assertJsonPath('case.comments.0.body', 'Carrier confirmed delivery.');

        $this->assertDatabaseHas('support_cases', ['id' => $caseId, 'business_id' => $business->id, 'conversation_id' => $conversation->id]);
    }

    public function test_case_endpoints_cannot_read_or_link_another_business_conversation(): void
    {
        $owner = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $owner->id]);
        $foreignBusiness = BusinessProfile::factory()->create();
        $foreignConversation = Conversation::factory()->create(['business_id' => $foreignBusiness->id]);
        $foreignCase = SupportCase::create([
            'business_id' => $foreignBusiness->id,
            'conversation_id' => $foreignConversation->id,
            'case_number' => 'CASE-FOREIGN01',
            'subject' => 'Foreign case',
            'status' => 'open',
            'priority' => 'normal',
        ]);

        $this->actingAs($owner, 'sanctum')->withHeader('X-Business-Id', (string) $business->id)
            ->getJson('/api/cases')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/cases/{$foreignCase->id}")->assertNotFound();
        $this->postJson('/api/cases', ['subject' => 'Bad link', 'conversation_id' => $foreignConversation->id])->assertNotFound();
        $this->assertDatabaseMissing('support_cases', ['business_id' => $business->id, 'subject' => 'Bad link']);
    }
}
