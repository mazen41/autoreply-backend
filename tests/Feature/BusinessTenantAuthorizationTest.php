<?php

namespace Tests\Feature;

use App\Models\AiActionLog;
use App\Models\BusinessProfile;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Sequence;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BusinessTenantAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_business_list_returns_owned_and_active_member_businesses_only(): void
    {
        [$owner, $owned] = $this->makeBusiness('Owned');
        [$secondOwner, $memberBusiness] = $this->makeBusiness('Team business');
        [, $inactiveBusiness] = $this->makeBusiness('Inactive business');
        $member = User::factory()->create();
        TeamMember::create([
            'business_id' => $memberBusiness->id,
            'user_id' => $member->id,
            'role' => 'agent',
            'is_active' => true,
        ]);
        TeamMember::create([
            'business_id' => $inactiveBusiness->id,
            'user_id' => $member->id,
            'role' => 'manager',
            'is_active' => false,
        ]);

        Sanctum::actingAs($member);
        $this->getJson('/api/businesses')->assertOk()
            ->assertJsonCount(1, 'businesses')
            ->assertJsonPath('businesses.0.id', $memberBusiness->id)
            ->assertJsonPath('businesses.0.role', 'agent');

        Sanctum::actingAs($owner);
        $this->getJson('/api/businesses')->assertOk()
            ->assertJsonCount(1, 'businesses')
            ->assertJsonPath('businesses.0.id', $owned->id)
            ->assertJsonPath('businesses.0.role', 'owner');
    }

    public function test_customer_endpoints_require_selected_business_instead_of_falling_back_to_owner(): void
    {
        $user = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $user->id]);
        $user->update(['business_id' => $business->id]);
        Sanctum::actingAs($user);
        $this->getJson('/api/customers')->assertStatus(422);
    }

    public function test_sequence_queries_require_and_honor_the_selected_business(): void
    {
        $owner = User::factory()->create();
        $primaryBusiness = BusinessProfile::factory()->create(['user_id' => $owner->id]);
        $owner->update(['business_id' => $primaryBusiness->id]);
        $otherOwner = User::factory()->create();
        $selectedBusiness = BusinessProfile::factory()->create(['user_id' => $otherOwner->id]);
        TeamMember::create([
            'business_id' => $selectedBusiness->id,
            'user_id' => $owner->id,
            'role' => 'manager',
            'is_active' => true,
        ]);
        $sequence = Sequence::create([
            'business_id' => $selectedBusiness->id,
            'name' => 'Selected business sequence',
            'trigger_type' => 'manual',
            'status' => 'draft',
        ]);

        Sanctum::actingAs($owner);
        $this->getJson('/api/sequences')->assertUnprocessable();
        $this->withHeader('X-Business-Id', (string) $selectedBusiness->id)
            ->getJson('/api/sequences')->assertOk()
            ->assertJsonPath('data.0.id', $sequence->id);
        $this->withHeader('X-Business-Id', (string) $primaryBusiness->id)
            ->getJson('/api/sequences')->assertOk()
            ->assertJsonMissing(['id' => $sequence->id]);
    }

    public function test_ai_action_routes_are_scoped_to_the_callers_business(): void
    {
        [$ownerA, $businessA, $action] = $this->makeAction();
        [$ownerB] = $this->makeBusiness('Owner B');
        Sanctum::actingAs($ownerB);

        $this->getJson("/api/businesses/{$businessA->id}/ai-actions")->assertForbidden();
        $this->getJson("/api/businesses/{$businessA->id}/ai-actions/pending")->assertForbidden();
        $this->getJson("/api/businesses/{$businessA->id}/ai-actions/statistics")->assertForbidden();
        $this->postJson("/api/businesses/{$businessA->id}/ai-actions/{$action->id}/approve")->assertForbidden();
        $this->postJson("/api/businesses/{$businessA->id}/ai-actions/{$action->id}/reject")->assertForbidden();

        $this->assertSame('pending', $action->fresh()->status);
    }

    public function test_analytics_routes_are_scoped_and_calculation_requires_write_access(): void
    {
        [, $businessA] = $this->makeBusiness('Owner A');
        [$ownerB] = $this->makeBusiness('Owner B');
        Sanctum::actingAs($ownerB);

        foreach ([
            'dashboard', 'csat', 'daily', 'ai-metrics', 'ratings',
        ] as $endpoint) {
            $this->getJson("/api/businesses/{$businessA->id}/analytics/{$endpoint}")->assertForbidden();
        }
        $this->postJson("/api/businesses/{$businessA->id}/analytics/calculate")->assertForbidden();
    }

    public function test_viewer_can_read_analytics_but_cannot_approve_or_recalculate(): void
    {
        [, $business, $action] = $this->makeAction();
        $viewer = User::factory()->create();
        TeamMember::create([
            'business_id' => $business->id,
            'user_id' => $viewer->id,
            'role' => 'viewer',
            'is_active' => true,
        ]);
        Sanctum::actingAs($viewer);

        $this->getJson("/api/businesses/{$business->id}/analytics/dashboard")->assertOk();
        $this->postJson("/api/businesses/{$business->id}/analytics/calculate")->assertForbidden();
        $this->postJson("/api/businesses/{$business->id}/ai-actions/{$action->id}/approve")->assertForbidden();
        $this->assertSame('pending', $action->fresh()->status);
    }

    public function test_failed_ai_action_approval_is_marked_failed_without_duplicate_log(): void
    {
        [$owner, $business, $action] = $this->makeAction();
        Sanctum::actingAs($owner);

        $response = $this->postJson("/api/businesses/{$business->id}/ai-actions/{$action->id}/approve");

        $response->assertOk()->assertJsonPath('success', false);
        $this->assertSame('failed', $action->fresh()->status);
        $this->assertSame(1, AiActionLog::count());
    }

    private function makeAction(): array
    {
        [$owner, $business] = $this->makeBusiness('Owner A');
        $channel = Channel::factory()->create([
            'user_id' => $owner->id,
            'business_id' => $business->id,
        ]);
        $conversation = Conversation::factory()->create([
            'business_id' => $business->id,
            'channel_id' => $channel->id,
        ]);
        $message = Message::factory()->create(['conversation_id' => $conversation->id]);
        $action = AiActionLog::create([
            'conversation_id' => $conversation->id,
            'message_id' => $message->id,
            'action_type' => 'unsupported_action',
            'action_payload' => ['action' => 'unsupported_action', 'data' => []],
            'status' => 'pending',
        ]);

        return [$owner, $business, $action];
    }

    private function makeBusiness(string $name): array
    {
        $user = User::factory()->create();
        $business = BusinessProfile::factory()->create([
            'user_id' => $user->id,
            'name' => $name,
        ]);

        return [$user, $business];
    }
}
