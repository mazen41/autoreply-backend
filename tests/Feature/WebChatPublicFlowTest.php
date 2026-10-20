<?php

namespace Tests\Feature;

use App\Models\BusinessProfile;
use App\Models\User;
use App\Models\WebChatSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebChatPublicFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_enabled_site_can_create_an_opaque_session_with_campaign_attribution(): void
    {
        $business = BusinessProfile::factory()->create([
            'web_chat_enabled' => true,
            'web_chat_allowed_domains' => ['shop.example.com'],
        ]);

        $response = $this->withHeader('Origin', 'https://shop.example.com')->postJson('/api/web-chat/sessions?business_id=' . $business->id, [
            'business_id' => $business->id,
            'site_url' => 'https://shop.example.com/products/shoes',
            'page_url' => 'https://shop.example.com/products/shoes?utm_campaign=spring',
            'referrer_url' => 'https://shop.example.com/category',
            'utm_source' => 'instagram',
            'utm_medium' => 'social',
            'utm_campaign' => 'spring',
        ])->assertCreated()->assertJsonPath('success', true);

        $sessionId = $response->json('session_id');
        $this->assertIsString($sessionId);
        $this->assertGreaterThanOrEqual(64, strlen($sessionId));
        $this->assertDatabaseHas('web_chat_sessions', [
            'business_id' => $business->id, 'session_id' => $sessionId,
            'utm_source' => 'instagram', 'utm_medium' => 'social', 'utm_campaign' => 'spring',
        ]);
    }

    public function test_disabled_or_unlisted_website_cannot_create_a_visitor_session(): void
    {
        $disabled = BusinessProfile::factory()->create();
        $this->withHeader('Origin', 'https://shop.example.com')->postJson('/api/web-chat/sessions?business_id=' . $disabled->id, ['business_id' => $disabled->id, 'site_url' => 'https://shop.example.com'])
            ->assertNotFound();

        $enabled = BusinessProfile::factory()->create(['web_chat_enabled' => true, 'web_chat_allowed_domains' => ['shop.example.com']]);
        $this->withHeader('Origin', 'https://attacker.example.net')->postJson('/api/web-chat/sessions?business_id=' . $enabled->id, ['business_id' => $enabled->id, 'site_url' => 'https://shop.example.com'])
            ->assertForbidden();
    }

    public function test_widget_preflight_and_session_access_use_the_registered_origin(): void
    {
        $business = BusinessProfile::factory()->create(['web_chat_enabled' => true, 'web_chat_allowed_domains' => ['shop.example.com']]);
        $this->withHeader('Origin', 'https://shop.example.com')->options('/api/web-chat/sessions?business_id=' . $business->id)
            ->assertNoContent()->assertHeader('Access-Control-Allow-Origin', 'https://shop.example.com');
        $this->withHeader('Origin', 'https://attacker.example.net')->options('/api/web-chat/sessions?business_id=' . $business->id)->assertForbidden();
    }

    public function test_dashboard_web_chat_settings_preflight_uses_api_cors_instead_of_widget_domain_validation(): void
    {
        $this->withHeaders([
            'Origin' => 'https://nazbiz.io',
            'Access-Control-Request-Method' => 'GET',
            'Access-Control-Request-Headers' => 'authorization,x-business-id,accept',
        ])->options('/api/web-chat/configuration')
            ->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', 'https://nazbiz.io');
    }

    public function test_hosted_iframe_checks_the_parent_site_against_the_allowlist(): void
    {
        $business = BusinessProfile::factory()->create(['web_chat_enabled' => true, 'web_chat_allowed_domains' => ['shop.example.com']]);
        $appOrigin = rtrim((string) config('app.frontend_url'), '/');
        $this->withHeader('Origin', $appOrigin)->postJson('/api/web-chat/sessions?business_id=' . $business->id, [
            'business_id' => $business->id,
            'site_url' => 'https://shop.example.com/product',
        ])->assertCreated();

        $this->withHeader('Origin', $appOrigin)->postJson('/api/web-chat/sessions?business_id=' . $business->id, [
            'business_id' => $business->id,
            'site_url' => 'https://attacker.example.net/product',
        ])->assertForbidden();
    }

    public function test_business_owner_can_manage_widget_configuration(): void
    {
        $owner = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $owner->id]);
        $this->actingAs($owner, 'sanctum')->withHeader('X-Business-Id', (string) $business->id)
            ->putJson('/api/web-chat/configuration', ['enabled' => true, 'allowed_domains' => ['Example.com']])
            ->assertOk()->assertJsonPath('enabled', true)->assertJsonPath('allowed_domains.0', 'example.com');
        $this->assertDatabaseHas('business_profiles', ['id' => $business->id, 'web_chat_enabled' => true]);
    }

    public function test_campaign_attribution_is_scoped_to_the_selected_business(): void
    {
        $owner = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $owner->id]);
        $foreignBusiness = BusinessProfile::factory()->create();
        WebChatSession::create(['business_id' => $business->id, 'session_id' => 'session-attribution-1', 'utm_source' => 'instagram', 'utm_medium' => 'social', 'utm_campaign' => 'launch']);
        WebChatSession::create(['business_id' => $foreignBusiness->id, 'session_id' => 'foreign-attribution-1', 'utm_source' => 'email', 'utm_medium' => 'email', 'utm_campaign' => 'launch']);

        $this->actingAs($owner, 'sanctum')->withHeader('X-Business-Id', (string) $business->id)
            ->getJson('/api/web-chat/attribution')
            ->assertOk()->assertJsonPath('total_sessions', 1)->assertJsonPath('sources.0.source', 'instagram');
    }
}
