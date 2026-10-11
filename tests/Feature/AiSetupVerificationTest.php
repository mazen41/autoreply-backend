<?php

namespace Tests\Feature;

use App\Models\Bot;
use App\Models\BusinessProfile;
use App\Models\BusinessKnowledgeFile;
use App\Models\Channel;
use App\Models\User;
use App\Models\TeamMember;
use App\Models\FeatureFlag;
use App\Models\BusinessFeatureFlagAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AiSetupVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_checklist_shows_each_missing_setup_requirement(): void
    {
        config(['services.gemini.api_key' => null, 'services.groq.api_key' => null, 'services.claude.api_key' => null]);
        [$user, $business] = $this->business();
        Sanctum::actingAs($user);

        $this->getJson('/api/ai/setup-verification', ['X-Business-Id' => (string) $business->id])
            ->assertOk()
            ->assertJsonPath('ready', false)
            ->assertJsonPath('checks.connected_channel.passed', false)
            ->assertJsonPath('checks.active_agent.passed', false)
            ->assertJsonPath('checks.provider_configured.passed', false)
            ->assertJsonPath('checks.knowledge_or_instructions.passed', false)
            ->assertJsonPath('checks.test_reply_generated.passed', false)
            ->assertJsonPath('checks.consent_policy_acknowledged.passed', false)
            ->assertJsonPath('checks.handoff_recipient_configured.passed', false);
    }

    public function test_channel_ai_cannot_be_enabled_until_readiness_checks_and_test_reply_pass(): void
    {
        [$user, $business, $channel] = $this->readyBusiness();
        $this->enableSetupVerification($business->id);
        Sanctum::actingAs($user);

        $this->patchJson('/api/channels/' . $channel->id, ['ai_enabled' => true], ['X-Business-Id' => (string) $business->id])
            ->assertUnprocessable()
            ->assertJsonPath('ready', false);

        $this->patchJson('/api/channels/' . $channel->id, ['ai_enabled' => 1], ['X-Business-Id' => (string) $business->id])
            ->assertUnprocessable()
            ->assertJsonPath('ready', false);

        $this->assertFalse((bool) $channel->fresh()->ai_enabled);
    }

    public function test_setup_verification_gate_is_globally_required(): void
    {
        [$user, $business, $channel] = $this->readyBusiness();
        Sanctum::actingAs($user);

        $this->patchJson('/api/channels/' . $channel->id, ['ai_enabled' => true], ['X-Business-Id' => (string) $business->id])
            ->assertUnprocessable()
            ->assertJsonPath('ready', false);

        $this->assertFalse((bool) $channel->fresh()->ai_enabled);
    }

    public function test_successful_test_reply_completes_verification_and_allows_ai_enablement(): void
    {
        [$user, $business, $channel] = $this->readyBusiness();
        $this->enableSetupVerification($business->id);
        config(['services.ai.provider' => 'gemini', 'services.gemini.api_key' => 'test-key']);
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => 'Hello, this is a test reply.']]]]],
            ], 200),
        ]);
        Sanctum::actingAs($user);

        $this->postJson('/api/knowledge/test', ['test_question' => 'Say hello'], ['X-Business-Id' => (string) $business->id])
            ->assertOk()
            ->assertJsonPath('test_response', 'Hello, this is a test reply.');

        $this->getJson('/api/ai/setup-verification', ['X-Business-Id' => (string) $business->id])
            ->assertOk()
            ->assertJsonPath('ready', true);

        $this->patchJson('/api/channels/' . $channel->id, ['ai_enabled' => true], ['X-Business-Id' => (string) $business->id])
            ->assertOk();
        $this->assertTrue((bool) $channel->fresh()->ai_enabled);

        $this->patchJson('/api/channels/' . $channel->id, ['ai_enabled' => false], ['X-Business-Id' => (string) $business->id])
            ->assertOk();
        $this->assertFalse((bool) $channel->fresh()->ai_enabled);
    }

    public function test_test_reply_is_invalidated_when_agent_instructions_change(): void
    {
        [$user, $business, $channel, $bot] = $this->readyBusiness();
        $this->enableSetupVerification($business->id);
        config(['services.ai.provider' => 'gemini', 'services.gemini.api_key' => 'test-key']);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => 'Hello.']]]]],
        ], 200)]);
        Sanctum::actingAs($user);

        $this->postJson('/api/knowledge/test', ['test_question' => 'Say hello'], ['X-Business-Id' => (string) $business->id])->assertOk();
        $this->getJson('/api/ai/setup-verification', ['X-Business-Id' => (string) $business->id])->assertJsonPath('ready', true);

        $bot->update(['ai_instructions' => 'Use a different response policy.']);
        $this->getJson('/api/ai/setup-verification', ['X-Business-Id' => (string) $business->id])
            ->assertOk()->assertJsonPath('ready', false)->assertJsonPath('checks.test_reply_generated.passed', false);
        $this->patchJson('/api/channels/' . $channel->id, ['ai_enabled' => true], ['X-Business-Id' => (string) $business->id])
            ->assertUnprocessable();
        $this->assertFalse((bool) $channel->fresh()->ai_enabled);
    }

    public function test_go_live_settings_can_be_acknowledged_and_are_business_scoped(): void
    {
        [$user, $business] = $this->business();
        Sanctum::actingAs($user);
        $this->putJson("/api/businesses/{$business->id}/go-live-settings", [
            'consent_policy_acknowledged' => true,
            'handoff_notification_email' => 'support@example.test',
        ])->assertOk();
        $this->getJson('/api/ai/setup-verification', ['X-Business-Id' => (string) $business->id])
            ->assertOk()->assertJsonPath('checks.consent_policy_acknowledged.passed', true)
            ->assertJsonPath('checks.handoff_recipient_configured.passed', true);

        $outsider = User::factory()->create();
        Sanctum::actingAs($outsider);
        $this->putJson("/api/businesses/{$business->id}/go-live-settings", ['consent_policy_acknowledged' => true])->assertForbidden();
    }

    public function test_viewer_cannot_mark_a_test_reply_as_successful(): void
    {
        [, $business] = $this->business();
        $viewer = User::factory()->create();
        TeamMember::create(['business_id' => $business->id, 'user_id' => $viewer->id, 'role' => 'viewer', 'is_active' => true]);
        Sanctum::actingAs($viewer);
        $this->postJson('/api/knowledge/test', ['test_question' => 'Say hello'], ['X-Business-Id' => (string) $business->id])
            ->assertForbidden();
        $this->assertNull($business->fresh()->ai_setup_tested_at);
    }

    public function test_new_channels_default_to_ai_replies_off(): void
    {
        [$user, $business] = $this->business();
        $channel = Channel::create([
            'user_id' => $user->id,
            'business_id' => $business->id,
            'type' => 'facebook',
            'page_id' => 'new-channel-' . $user->id,
            'status' => 'connected',
            'access_token' => 'test-token',
        ]);

        $this->assertFalse((bool) $channel->fresh()->ai_enabled);
    }

    private function business(): array
    {
        $user = User::factory()->create();
        $business = BusinessProfile::create([
            'user_id' => $user->id,
            'business_name' => 'Setup Test Store',
            'ai_provider' => 'gemini',
        ]);

        return [$user, $business];
    }

    private function enableSetupVerification(int $businessId): void
    {
        $flag = FeatureFlag::where('key', 'phase3_setup_verification_required')->firstOrFail();
        BusinessFeatureFlagAssignment::create([
            'business_id' => $businessId,
            'feature_flag_id' => $flag->id,
            'enabled' => true,
        ]);
    }

    private function readyBusiness(): array
    {
        [$user, $business] = $this->business();
        $business->update([
            'ai_provider' => 'gemini', 'ai_instructions' => 'Answer clearly and accurately.',
            'consent_policy_acknowledged_at' => now(), 'handoff_notification_email' => 'support@example.test',
        ]);
        $channel = Channel::create([
            'user_id' => $user->id,
            'business_id' => $business->id,
            'type' => 'instagram',
            'page_id' => 'setup-page-' . $user->id,
            'status' => 'connected',
            'access_token' => 'test-token',
            'ai_enabled' => false,
        ]);
        $bot = Bot::create([
            'business_profile_id' => $business->id,
            'name' => 'Setup Agent',
            'status' => 'active',
        ]);
        $bot->channels()->attach($channel->id, ['is_primary' => true]);
        config(['services.ai.provider' => 'gemini', 'services.gemini.api_key' => 'test-key']);

        return [$user, $business, $channel, $bot];
    }
}
