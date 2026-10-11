<?php

namespace Tests\Feature;

use App\Models\BusinessProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MediaProcessingDisclosureTest extends TestCase
{
    use RefreshDatabase;

    public function test_business_owner_can_record_and_revoke_media_disclosure_acknowledgment(): void
    {
        $owner = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $owner->id]);

        $this->actingAs($owner, 'sanctum')
            ->withHeader('X-Business-Id', (string) $business->id)
            ->getJson('/api/media-processing/disclosure')
            ->assertOk()
            ->assertJson(['acknowledged' => false, 'acknowledged_at' => null]);

        $this->actingAs($owner, 'sanctum')
            ->withHeader('X-Business-Id', (string) $business->id)
            ->putJson('/api/media-processing/disclosure', ['acknowledged' => true])
            ->assertOk()
            ->assertJson(['acknowledged' => true]);
        $this->assertNotNull($business->fresh()->media_processing_disclosed_at);

        $this->actingAs($owner, 'sanctum')
            ->withHeader('X-Business-Id', (string) $business->id)
            ->putJson('/api/media-processing/disclosure', ['acknowledged' => false])
            ->assertOk()
            ->assertJson(['acknowledged' => false, 'acknowledged_at' => null]);
        $this->assertNull($business->fresh()->media_processing_disclosed_at);
    }

    public function test_disclosure_setting_cannot_be_changed_for_another_business(): void
    {
        $owner = User::factory()->create();
        $foreignOwner = User::factory()->create();
        $foreignBusiness = BusinessProfile::factory()->create(['user_id' => $foreignOwner->id]);

        $this->actingAs($owner, 'sanctum')
            ->withHeader('X-Business-Id', (string) $foreignBusiness->id)
            ->putJson('/api/media-processing/disclosure', ['acknowledged' => true])
            ->assertForbidden();
        $this->assertNull($foreignBusiness->fresh()->media_processing_disclosed_at);
    }
}
