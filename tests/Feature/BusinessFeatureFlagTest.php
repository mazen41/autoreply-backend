<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\BusinessProfile;
use App\Models\FeatureFlag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BusinessFeatureFlagTest extends TestCase
{
    use RefreshDatabase;

    public function test_global_rollout_flag_is_enabled_and_cannot_be_overridden_per_business(): void
    {
        $owner = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $owner->id]);
        $otherBusiness = BusinessProfile::factory()->create();
        $owner->forceFill(['business_id' => $business->id])->save();
        Sanctum::actingAs($owner);

        $flag = FeatureFlag::where('key', 'phase3_no_agent_fail_closed')->firstOrFail();
        self::assertTrue(FeatureFlag::isEnabled($flag->key, $business->id));
        self::assertTrue(FeatureFlag::isEnabled($flag->key, $otherBusiness->id));

        $this->getJson("/api/businesses/{$business->id}/rollout-flags")
            ->assertOk()
            ->assertJsonFragment(['key' => $flag->key, 'enabled' => true, 'global' => true]);

        $this->putJson("/api/businesses/{$business->id}/rollout-flags/{$flag->key}", ['enabled' => true])
            ->assertNotFound();

        self::assertTrue(FeatureFlag::isEnabled($flag->key, $business->id));
        self::assertTrue(FeatureFlag::isEnabled($flag->key, $otherBusiness->id));
        $this->assertDatabaseMissing('business_feature_flag_assignments', [
            'business_id' => $business->id,
            'feature_flag_id' => $flag->id,
        ]);
    }

    public function test_business_member_cannot_toggle_flag_for_another_business(): void
    {
        $user = User::factory()->create();
        $business = BusinessProfile::factory()->create();
        Sanctum::actingAs($user);

        $flag = FeatureFlag::where('key', 'phase3_no_agent_fail_closed')->firstOrFail();
        $this->putJson("/api/businesses/{$business->id}/rollout-flags/{$flag->key}", ['enabled' => true])
            ->assertForbidden();

        $this->assertDatabaseMissing('business_feature_flag_assignments', [
            'business_id' => $business->id,
            'feature_flag_id' => $flag->id,
        ]);
    }

    public function test_message_safety_rollouts_are_listed_and_globally_enabled(): void
    {
        $owner = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $owner->id]);
        $owner->forceFill(['business_id' => $business->id])->save();
        Sanctum::actingAs($owner);

        $response = $this->getJson("/api/businesses/{$business->id}/rollout-flags")
            ->assertOk();

        $flags = collect($response->json('flags'))->keyBy('key');
        foreach ([
            'phase3_no_agent_fail_closed',
            'phase3_setup_verification_required',
            'phase5_workflow_reply_safety',
            'phase5_sequence_takeover_guard',
        ] as $key) {
            self::assertArrayHasKey($key, $flags->all());
            self::assertTrue($flags[$key]['enabled']);
            self::assertTrue($flags[$key]['global']);
            self::assertTrue(FeatureFlag::isEnabled($key, $business->id));
        }
    }
}
