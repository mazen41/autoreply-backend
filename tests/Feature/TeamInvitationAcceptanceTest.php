<?php

namespace Tests\Feature;

use App\Models\BusinessProfile;
use App\Models\TeamMember;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TeamInvitationAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\App\Http\Middleware\PlanEnforcement::class);
    }

    public function test_existing_user_is_not_added_until_invitation_is_accepted(): void
    {
        Mail::fake();
        $owner = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $owner->id]);
        $invitee = User::factory()->create(['email' => 'invitee@example.test']);
        Sanctum::actingAs($owner);

        $this->postJson("/api/businesses/{$business->id}/team/invite", [
            'email' => $invitee->email,
            'role' => 'agent',
        ])->assertCreated()->assertJsonPath('invitation.status', 'pending');

        $this->assertDatabaseMissing('team_members', ['business_id' => $business->id, 'user_id' => $invitee->id]);
        $invitation = TeamInvitation::firstOrFail();
        $token = str_repeat('a', 64);
        $invitation->update(['token_hash' => hash('sha256', $token)]);
        $this->assertSame('invitee@example.test', $invitation->email);

        Sanctum::actingAs($invitee);
        $this->postJson('/api/team/invitations/accept', ['token' => $token])
            ->assertOk()->assertJsonPath('team_member.role', 'agent');
        $this->assertDatabaseHas('team_members', ['business_id' => $business->id, 'user_id' => $invitee->id, 'role' => 'agent']);
    }

    public function test_invited_user_can_list_and_accept_invitation_from_in_app_notification(): void
    {
        Mail::fake();
        $owner = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $owner->id]);
        $invitee = User::factory()->create(['email' => 'notice@example.test']);
        Sanctum::actingAs($owner);
        $response = $this->postJson("/api/businesses/{$business->id}/team/invite", [
            'email' => $invitee->email,
            'role' => 'viewer',
        ])->assertCreated();
        $invitationId = $response->json('invitation.id');

        Sanctum::actingAs($invitee);
        $this->getJson('/api/team/invitations')
            ->assertOk()
            ->assertJsonPath('0.id', $invitationId)
            ->assertJsonPath('0.role', 'viewer');
        $this->postJson("/api/team/invitations/{$invitationId}/accept")
            ->assertOk()->assertJsonPath('team_member.role', 'viewer');
        $this->assertDatabaseHas('team_members', ['business_id' => $business->id, 'user_id' => $invitee->id, 'role' => 'viewer']);
    }

    public function test_invitation_list_and_id_accept_are_scoped_to_invited_email(): void
    {
        $owner = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $owner->id]);
        $invitee = User::factory()->create(['email' => 'right@example.test']);
        $attacker = User::factory()->create(['email' => 'wrong@example.test']);
        $invitation = TeamInvitation::create([
            'business_id' => $business->id,
            'invited_by' => $owner->id,
            'email' => $invitee->email,
            'role' => 'agent',
            'token_hash' => hash('sha256', str_repeat('b', 64)),
            'expires_at' => now()->addDay(),
            'status' => 'pending',
        ]);

        Sanctum::actingAs($attacker);
        $this->getJson('/api/team/invitations')->assertOk()->assertExactJson([]);
        $this->postJson("/api/team/invitations/{$invitation->id}/accept")->assertForbidden();
        $this->assertDatabaseMissing('team_members', ['business_id' => $business->id, 'user_id' => $attacker->id]);
    }

    public function test_revoked_and_expired_invitations_cannot_be_accepted_by_id(): void
    {
        $owner = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $owner->id]);
        $invitee = User::factory()->create(['email' => 'invitee@example.test']);
        $revoked = TeamInvitation::create([
            'business_id' => $business->id, 'invited_by' => $owner->id, 'email' => $invitee->email,
            'role' => 'agent', 'token_hash' => hash('sha256', str_repeat('c', 64)),
            'expires_at' => now()->addDay(), 'status' => 'revoked',
        ]);
        $expired = TeamInvitation::create([
            'business_id' => $business->id, 'invited_by' => $owner->id, 'email' => $invitee->email,
            'role' => 'agent', 'token_hash' => hash('sha256', str_repeat('d', 64)),
            'expires_at' => now()->subSecond(), 'status' => 'pending',
        ]);
        Sanctum::actingAs($invitee);
        $this->postJson("/api/team/invitations/{$revoked->id}/accept")->assertGone();
        $this->postJson("/api/team/invitations/{$expired->id}/accept")->assertGone();
        $this->assertDatabaseMissing('team_members', ['business_id' => $business->id, 'user_id' => $invitee->id]);
    }

    public function test_owner_can_view_resend_and_revoke_pending_invitations(): void
    {
        Mail::fake();
        $owner = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $owner->id]);
        Sanctum::actingAs($owner);
        $created = $this->postJson("/api/businesses/{$business->id}/team/invite", [
            'email' => 'resend@example.test', 'role' => 'agent',
        ])->assertCreated();
        $old = TeamInvitation::findOrFail($created->json('invitation.id'));

        $this->getJson("/api/businesses/{$business->id}/team/invitations")
            ->assertOk()->assertJsonPath('0.email', 'resend@example.test');
        $resent = $this->postJson("/api/businesses/{$business->id}/team/invitations/{$old->id}/resend")
            ->assertOk();
        $this->assertSame('expired', $old->fresh()->status);
        $replacementId = $resent->json('invitation.id');
        $this->assertNotSame($old->id, $replacementId);
        $this->deleteJson("/api/businesses/{$business->id}/team/invitations/{$replacementId}")->assertOk();
        $this->assertDatabaseHas('team_invitations', ['id' => $replacementId, 'status' => 'revoked']);
    }
}
