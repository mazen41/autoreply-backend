<?php

namespace Tests\Feature;

use App\Models\BusinessProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class N8nRoutesSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_n8n_endpoints_are_not_accessible_without_authentication(): void
    {
        Http::fake();

        foreach ([
            ['/api/n8n/rate-limit/check', ['user_id' => '1', 'business_id' => '1']],
            ['/api/n8n/language/detect', ['message' => 'hello']],
            ['/api/n8n/conversation/memory', ['conversation_id' => '1']],
            ['/api/n8n/ai/ultimate-process', ['message' => 'hello', 'business_id' => '1']],
            ['/api/n8n/escalate', ['conversation_id' => '1']],
        ] as [$path, $payload]) {
            $this->postJson($path, $payload)->assertUnauthorized();
        }
    }

    public function test_authenticated_business_user_can_use_protected_n8n_route(): void
    {
        $user = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $user->id]);
        Sanctum::actingAs($user);

        $this->postJson('/api/n8n/rate-limit/check', [
            'user_id' => (string) $user->id,
            'business_id' => (string) $business->id,
        ])->assertOk();
    }
}
