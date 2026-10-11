<?php

namespace Tests\Feature;

use App\Models\BusinessProfile;
use App\Models\Channel;
use App\Models\OnboardingProgress;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OnboardingRealityTest extends TestCase
{
    use RefreshDatabase;

    public function test_skip_does_not_mark_setup_complete_and_turns_channel_ai_off(): void
    {
        $user = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $user->id]);
        $channel = Channel::create([
            'user_id' => $user->id, 'business_id' => $business->id, 'type' => 'instagram',
            'page_id' => 'skip-channel', 'access_token' => 'test-token', 'status' => 'connected', 'ai_enabled' => true,
        ]);

        $this->actingAs($user)->postJson('/api/onboarding/skip')->assertOk()
            ->assertJsonPath('completed', false)->assertJsonPath('skipped', true);

        $this->assertFalse((bool) $channel->fresh()->ai_enabled);
        $this->assertFalse($this->app->make(\App\Services\OnboardingService::class)->getOnboardingStatus($user->id)['completed']);
        $this->assertNotSame(100, OnboardingProgress::where('user_id', $user->id)->value('progress_percentage'));
    }

    public function test_client_cannot_claim_connected_channel_step_without_a_connected_channel(): void
    {
        $user = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)->withHeader('X-Business-Id', (string) $business->id)
            ->postJson('/api/onboarding/complete-step', ['step' => 'connect_channel'])
            ->assertUnprocessable();

        $this->assertFalse($this->app->make(\App\Services\OnboardingService::class)->hasCompletedStep($user->id, 'connect_channel'));
    }

    public function test_wizard_cannot_claim_setup_complete_without_a_verified_ai_agent(): void
    {
        $user = User::factory()->create();
        BusinessProfile::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)->postJson('/api/onboarding/complete')
            ->assertOk()
            ->assertJsonPath('completed', false)
            ->assertJsonPath('setup_ready', false);

        $this->assertTrue((bool) $user->fresh()->onboarding_completed, 'Wizard dismissal is tracked separately from verified setup completion.');
        $this->assertFalse($this->app->make(\App\Services\OnboardingService::class)->getOnboardingStatus($user->id)['completed']);
    }

    public function test_generic_progress_endpoint_cannot_set_complete_or_unknown_steps(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/api/onboarding/progress', ['step' => 'complete'])->assertUnprocessable();
        $this->actingAs($user)->postJson('/api/onboarding/progress', ['step' => 'invented'])->assertUnprocessable();
    }

    public function test_newly_created_business_defaults_order_submission_to_off_but_legacy_profiles_default_on(): void
    {
        $legacyUser = User::factory()->create();
        $legacyBusiness = BusinessProfile::factory()->create(['user_id' => $legacyUser->id]);
        $this->assertTrue((bool) $legacyBusiness->auto_order_confirmation_enabled);

        $newUser = User::factory()->create();
        $this->actingAs($newUser)->postJson('/api/onboarding/skip')->assertOk();
        $newBusiness = BusinessProfile::where('user_id', $newUser->id)->firstOrFail();
        $this->assertFalse((bool) $newBusiness->auto_order_confirmation_enabled);
    }
}
