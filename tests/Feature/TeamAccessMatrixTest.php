<?php

namespace Tests\Feature;

use App\Models\BusinessProfile;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TeamAccessMatrixTest extends TestCase
{
    use RefreshDatabase;

    private BusinessProfile $business;
    private User $owner;
    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->business = BusinessProfile::factory()->create(['user_id' => $this->owner->id]);
        $channel = Channel::factory()->create(['business_id' => $this->business->id, 'user_id' => $this->owner->id]);
        $this->conversation = Conversation::factory()->create([
            'business_id' => $this->business->id,
            'channel_id' => $channel->id,
        ]);
    }

    private function member(string $role): User
    {
        $user = User::factory()->create();
        TeamMember::create([
            'business_id' => $this->business->id,
            'user_id' => $user->id,
            'role' => $role,
            'is_active' => true,
            'joined_at' => now(),
        ]);
        return $user;
    }

    public function test_invite_is_not_blocked_by_plan_limits(): void
    {
        Sanctum::actingAs($this->owner);

        $this->postJson("/api/businesses/{$this->business->id}/team/invite", [
            'email' => 'newperson@example.com',
            'role' => 'agent',
        ])->assertStatus(201);
    }

    public function test_manager_and_agent_can_assign_conversations(): void
    {
        $agent = $this->member('agent');
        $manager = $this->member('manager');

        Sanctum::actingAs($manager);
        $this->postJson("/api/conversations/{$this->conversation->id}/assign", ['agent_id' => $agent->id])
            ->assertOk();

        Sanctum::actingAs($agent);
        $this->postJson("/api/conversations/{$this->conversation->id}/assign", ['agent_id' => $agent->id])
            ->assertOk();

        $this->assertDatabaseHas('conversations', [
            'id' => $this->conversation->id,
            'assigned_agent_id' => $agent->id,
        ]);
    }

    public function test_viewer_cannot_assign_conversations(): void
    {
        $viewer = $this->member('viewer');
        $agent = $this->member('agent');

        Sanctum::actingAs($viewer);
        $this->postJson("/api/conversations/{$this->conversation->id}/assign", ['agent_id' => $agent->id])
            ->assertStatus(403);
    }

    public function test_viewer_cannot_reply_to_conversations(): void
    {
        $viewer = $this->member('viewer');

        Sanctum::actingAs($viewer);
        $status = $this->postJson("/api/inbox/{$this->conversation->id}/reply", ['message' => 'hello'])->status();

        // Validation (422) can run before the permission check; the point is that no message is sent.
        $this->assertContains($status, [403, 404, 422], "Viewer reply should be denied, got {$status}");
        $this->assertDatabaseMissing('messages', ['conversation_id' => $this->conversation->id, 'content' => 'hello']);
    }

    public function test_user_without_membership_gets_not_found_on_assign(): void
    {
        $outsider = User::factory()->create();
        $agent = $this->member('agent');

        Sanctum::actingAs($outsider);
        $this->postJson("/api/conversations/{$this->conversation->id}/assign", ['agent_id' => $agent->id])
            ->assertStatus(404);
    }

    public function test_business_owner_can_be_assigned_without_membership_row(): void
    {
        $manager = $this->member('manager');

        Sanctum::actingAs($manager);
        $this->postJson("/api/conversations/{$this->conversation->id}/assign", ['agent_id' => $this->owner->id])
            ->assertOk();
    }

    public function test_agent_can_submit_feedback_on_ai_message(): void
    {
        $agent = $this->member('agent');
        $message = Message::factory()->create([
            'conversation_id' => $this->conversation->id,
            'is_ai' => true,
        ]);

        Sanctum::actingAs($agent);
        $this->postJson("/api/messages/{$message->id}/feedback", ['feedback' => 'positive'])
            ->assertOk();
    }

    public function test_outsider_cannot_submit_feedback(): void
    {
        $outsider = User::factory()->create();
        $message = Message::factory()->create([
            'conversation_id' => $this->conversation->id,
            'is_ai' => true,
        ]);

        Sanctum::actingAs($outsider);
        $this->postJson("/api/messages/{$message->id}/feedback", ['feedback' => 'positive'])
            ->assertStatus(404);
    }

    public function test_removed_member_loses_access_immediately(): void
    {
        $agent = $this->member('agent');
        $membership = TeamMember::where('user_id', $agent->id)->firstOrFail();

        Sanctum::actingAs($this->owner);
        $this->deleteJson("/api/businesses/{$this->business->id}/team/{$membership->id}")->assertOk();

        Sanctum::actingAs($agent);
        $this->getJson("/api/businesses/{$this->business->id}/me")->assertStatus(404);
    }

    public function test_user_cannot_change_own_role(): void
    {
        $manager = $this->member('manager');
        $membership = TeamMember::where('user_id', $manager->id)->firstOrFail();

        Sanctum::actingAs($manager);
        $this->patchJson("/api/businesses/{$this->business->id}/team/{$membership->id}/role", ['role' => 'owner'])
            ->assertStatus(403);
    }

    public function test_manager_cannot_grant_owner_role(): void
    {
        $manager = $this->member('manager');
        $agent = $this->member('agent');
        $membership = TeamMember::where('user_id', $agent->id)->firstOrFail();

        // Managers lack manage_team, so this is blocked before the owner check.
        Sanctum::actingAs($manager);
        $this->patchJson("/api/businesses/{$this->business->id}/team/{$membership->id}/role", ['role' => 'owner'])
            ->assertStatus(403);
    }

    public function test_me_endpoint_returns_role_and_permissions(): void
    {
        $viewer = $this->member('viewer');

        Sanctum::actingAs($viewer);
        $response = $this->getJson("/api/businesses/{$this->business->id}/me")->assertOk();

        $response->assertJsonPath('role', 'viewer');
        $this->assertContains('conversation_read', $response->json('permissions'));
        $this->assertNotContains('conversation_write', $response->json('permissions'));
        $this->assertNotContains('manage_team', $response->json('permissions'));
    }

    public function test_team_index_returns_owner_once_and_excludes_owner_row(): void
    {
        // Stray owner membership row must not produce a duplicate owner entry.
        TeamMember::create([
            'business_id' => $this->business->id, 'user_id' => $this->owner->id, 'role' => 'owner', 'is_active' => true,
        ]);
        $this->member('agent');

        Sanctum::actingAs($this->owner);
        $response = $this->getJson("/api/businesses/{$this->business->id}/team")->assertOk();

        $response->assertJsonPath('owner.id', $this->owner->id);
        $members = $response->json('members');
        $this->assertCount(1, $members);
        $this->assertNotEquals($this->owner->id, $members[0]['user_id']);
    }

    public function test_owner_me_endpoint_returns_owner_role(): void
    {
        Sanctum::actingAs($this->owner);
        $this->getJson("/api/businesses/{$this->business->id}/me")
            ->assertOk()
            ->assertJsonPath('role', 'owner');
    }
}
