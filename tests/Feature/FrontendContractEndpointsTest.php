<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\BusinessProfile;
use App\Models\CampaignTemplate;
use App\Models\Segment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FrontendContractEndpointsTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_key_secret_is_only_returned_once_and_actions_are_user_scoped(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        Sanctum::actingAs($owner);

        $created = $this->postJson('/api/api-keys', [
            'name' => 'CRM integration',
            'scopes' => ['conversations:read', 'messages:write'],
        ])->assertCreated()->assertJsonPath('name', 'CRM integration')->assertJsonPath('scopes.0', 'conversations:read');
        $secret = $created->json('key');
        $this->assertIsString($secret);
        $this->assertStringStartsWith('naz_live_', $secret);

        $key = ApiKey::where('user_id', $owner->id)->firstOrFail();
        $digest = hash('sha256', $secret);
        $this->assertSame($digest, $key->key);
        $this->assertSame($digest, $key->secret_hash);
        $this->assertNull($key->secret_ciphertext);

        $this->getJson('/api/public/conversations', ['Authorization' => 'Bearer ' . $secret])
            ->assertOk()
            ->assertJsonPath('total', 0);
        $this->getJson('/api/public/conversations?api_key=' . urlencode($secret))
            ->assertStatus(401);

        $listed = $this->getJson('/api/api-keys')->assertOk()->json('data.0');
        $this->assertNotSame($secret, $listed['key']);
        $this->assertStringEndsWith(substr($secret, -4), $listed['key']);

        Sanctum::actingAs($otherUser);
        $this->postJson("/api/api-keys/{$key->id}/toggle")->assertNotFound();
        $this->deleteJson("/api/api-keys/{$key->id}")->assertNotFound();
        $this->assertTrue($key->fresh()->is_active);
    }

    public function test_campaign_segments_and_templates_are_scoped_to_selected_business(): void
    {
        $owner = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $owner->id]);
        $otherOwner = User::factory()->create();
        $otherBusiness = BusinessProfile::factory()->create(['user_id' => $otherOwner->id]);
        Sanctum::actingAs($owner);

        $segmentResponse = $this->withHeader('X-Business-Id', (string) $business->id)
            ->postJson('/api/segments', [
                'name' => 'VIP customers',
                'rules' => ['tags' => ['vip'], 'min_orders' => 2],
            ])->assertCreated();
        $segmentId = $segmentResponse->json('data.id');
        $this->assertDatabaseHas('segments', [
            'id' => $segmentId,
            'business_profile_id' => $business->id,
        ]);

        $templateResponse = $this->withHeader('X-Business-Id', (string) $business->id)
            ->postJson('/api/campaign-templates', [
                'title' => 'Order update',
                'channel_type' => 'whatsapp',
                'body' => 'Hello {{first_name}}, order {{order.id}} is ready.',
            ])->assertCreated()->assertJsonPath('data.variables', ['first_name', 'order.id']);
        $templateId = $templateResponse->json('data.id');

        $this->withHeader('X-Business-Id', (string) $business->id)
            ->getJson('/api/segments')->assertOk()->assertJsonPath('data.0.id', $segmentId);
        $this->withHeader('X-Business-Id', (string) $business->id)
            ->getJson('/api/campaign-templates')->assertOk()->assertJsonPath('data.0.id', $templateId);

        $this->withHeader('X-Business-Id', (string) $otherBusiness->id)
            ->putJson("/api/segments/{$segmentId}", ['name' => 'Stolen'])->assertForbidden();
        $this->withHeader('X-Business-Id', (string) $otherBusiness->id)
            ->deleteJson("/api/campaign-templates/{$templateId}")->assertForbidden();

        $this->assertSame('VIP customers', Segment::findOrFail($segmentId)->name);
        $this->assertSame($business->id, CampaignTemplate::findOrFail($templateId)->business_profile_id);
    }
}
