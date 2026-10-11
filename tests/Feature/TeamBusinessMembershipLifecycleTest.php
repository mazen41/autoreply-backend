<?php

namespace Tests\Feature;

use App\Models\BusinessProfile;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TeamBusinessMembershipLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\App\Http\Middleware\PlanEnforcement::class);
    }

    public function test_removal_replaces_primary_business_with_another_active_membership(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $firstBusiness = BusinessProfile::factory()->create(['user_id' => $owner->id]);
        $secondOwner = User::factory()->create();
        $secondBusiness = BusinessProfile::factory()->create(['user_id' => $secondOwner->id]);
        $removedMembership = TeamMember::create([
            'business_id' => $firstBusiness->id, 'user_id' => $member->id, 'role' => 'agent', 'is_active' => true,
        ]);
        TeamMember::create([
            'business_id' => $secondBusiness->id, 'user_id' => $member->id, 'role' => 'viewer', 'is_active' => true,
        ]);
        $member->update(['business_id' => $firstBusiness->id]);

        Sanctum::actingAs($owner);
        $this->deleteJson("/api/businesses/{$firstBusiness->id}/team/{$removedMembership->id}")->assertOk();

        $this->assertDatabaseMissing('team_members', ['id' => $removedMembership->id]);
        $this->assertSame($secondBusiness->id, (int) $member->fresh()->business_id);
    }

    public function test_removal_clears_primary_business_when_no_active_membership_remains(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $owner->id]);
        $membership = TeamMember::create([
            'business_id' => $business->id, 'user_id' => $member->id, 'role' => 'agent', 'is_active' => true,
        ]);
        $member->update(['business_id' => $business->id]);

        Sanctum::actingAs($owner);
        $this->deleteJson("/api/businesses/{$business->id}/team/{$membership->id}")->assertOk();

        $this->assertNull($member->fresh()->business_id);
    }
}
