<?php

namespace Tests\Feature;

use App\Models\BusinessProfile;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OnboardingTenantSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_onboarding_does_not_replace_an_existing_primary_business(): void
    {
        $user = User::factory()->create();
        $primaryBusiness = BusinessProfile::factory()->create(['user_id' => $user->id]);
        $user->update(['business_id' => $primaryBusiness->id]);

        $this->actingAs($user)->postJson('/api/onboarding/step1', [
            'business_type' => 'retail',
        ])->assertOk();

        $this->assertSame($primaryBusiness->id, $user->fresh()->business_id);
        $this->assertDatabaseHas('business_profiles', ['user_id' => $user->id]);
    }

    public function test_team_member_onboarding_never_creates_a_private_fallback_business(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $owner->id]);
        $member->update(['business_id' => $business->id]);
        TeamMember::create([
            'business_id' => $business->id,
            'user_id' => $member->id,
            'role' => 'manager',
            'is_active' => true,
        ]);

        $beforeCount = BusinessProfile::count();
        $this->actingAs($member)->postJson('/api/onboarding/step1', [
            'business_type' => 'retail',
        ])->assertForbidden();

        $this->assertSame($beforeCount, BusinessProfile::count());
        $this->assertSame($business->id, $member->fresh()->business_id);
    }
}
