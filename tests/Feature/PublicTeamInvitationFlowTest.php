<?php

namespace Tests\Feature;

use App\Models\BusinessProfile;
use App\Models\TeamInvitation;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PublicTeamInvitationFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_invitee_can_preview_and_accept_invitation_with_an_account(): void
    {
        [$business, $token, $invitation] = $this->makeInvitation('new.person@example.com');

        $this->getJson("/api/team-invitations/check/{$token}")
            ->assertOk()
            ->assertJsonPath('email', 'new.person@example.com')
            ->assertJsonPath('role', 'agent')
            ->assertJsonPath('business_name', $business->business_name)
            ->assertJsonPath('requires_existing_account_password', false)
            ->assertJsonMissingPath('token_hash');

        $response = $this->postJson("/api/team-invitations/{$token}/accept", [
            'name' => 'New Person',
            'username' => 'ignored-legacy-field',
            'password' => 'a-strong-password',
        ])->assertOk()->assertJsonPath('success', true)->assertJsonStructure(['token', 'user', 'team_member']);

        $user = User::where('email', 'new.person@example.com')->firstOrFail();
        $this->assertTrue(Hash::check('a-strong-password', $user->password));
        $this->assertDatabaseHas('team_members', [
            'business_id' => $business->id,
            'user_id' => $user->id,
            'role' => 'agent',
            'is_active' => true,
        ]);
        $this->assertSame('accepted', $invitation->fresh()->status);
        $this->assertNotEmpty($response->json('token'));
    }

    public function test_existing_account_must_prove_password_before_acceptance(): void
    {
        $existing = User::factory()->create(['email' => 'existing@example.com', 'password' => 'current-password']);
        [$business, $token, $invitation] = $this->makeInvitation('existing@example.com');

        $this->getJson("/api/team-invitations/check/{$token}")
            ->assertOk()->assertJsonPath('requires_existing_account_password', true);
        $this->postJson("/api/team-invitations/{$token}/accept", [
            'name' => 'Existing User', 'password' => 'wrong-password',
        ])->assertForbidden();
        $this->assertSame('pending', $invitation->fresh()->status);
        $this->assertDatabaseMissing('team_members', ['business_id' => $business->id, 'user_id' => $existing->id]);

        $this->postJson("/api/team-invitations/{$token}/accept", [
            'name' => $existing->name, 'password' => 'current-password',
        ])->assertOk()->assertJsonPath('user.id', $existing->id);
        $this->assertDatabaseHas('team_members', ['business_id' => $business->id, 'user_id' => $existing->id]);
    }

    public function test_invitation_cannot_be_accepted_or_rejected_twice(): void
    {
        [, $token, $invitation] = $this->makeInvitation('once@example.com');
        $this->postJson("/api/team-invitations/{$token}/reject")->assertOk()->assertJsonPath('success', true);
        $this->getJson("/api/team-invitations/check/{$token}")->assertStatus(410);
        $this->postJson("/api/team-invitations/{$token}/accept", [
            'name' => 'Once', 'password' => 'a-strong-password',
        ])->assertStatus(410);
        $this->assertSame('revoked', $invitation->fresh()->status);
    }

    private function makeInvitation(string $email): array
    {
        $owner = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $owner->id]);
        $token = str_repeat('InviteToken7', 5) . 'ABCD';
        $invitation = TeamInvitation::create([
            'business_id' => $business->id,
            'invited_by' => $owner->id,
            'email' => $email,
            'role' => 'agent',
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDay(),
            'status' => 'pending',
        ]);
        return [$business, $token, $invitation];
    }
}
