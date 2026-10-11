<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\BusinessProfile;
use App\Models\User;
use App\Services\OAuthStateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Laravel\Sanctum\Sanctum;

class SallaOAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_salla_connect_redirects_to_oauth(): void
    {
        $user = User::forceCreate([
            'name' => 'Test',
            'email' => 'test' . rand() . '@test.com',
            'password' => '123'
        ]);
        $business = BusinessProfile::create(['user_id' => $user->id, 'name' => 'Salla test business']);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/channels/connect/salla', [], ['X-Business-Id' => (string) $business->id]);
        $response->assertOk();
        $this->assertStringContainsString('accounts.salla.sa', $response->json('url'));
    }

    public function test_salla_connect_without_token_fails(): void
    {
        $response = $this->get('/api/channels/connect/salla');
        $response->assertStatus(405);
    }

    public function test_salla_callback_creates_channel(): void
    {
        $user = User::forceCreate([
            'name' => 'Test',
            'email' => 'test' . rand() . '@test.com',
            'password' => '123'
        ]);
        $business = BusinessProfile::create(['user_id' => $user->id, 'name' => 'Salla test business']);
        $state = app(OAuthStateService::class)->issue('salla', $user->id, ['business_id' => $business->id]);
        
        // Mock the Salla service responses
        $this->mockSallaServiceResponses();

        $response = $this->get('/api/channels/callback/salla?code=test_code&state=' . $state);

        $response->assertRedirect(env('FRONTEND_URL') . '/dashboard/channels?success=salla_connected');

        $this->assertDatabaseHas('channels', [
            'user_id' => $user->id,
            'type' => 'salla',
            'status' => 'connected',
        ]);
    }

    public function test_salla_callback_with_error(): void
    {
        $user = User::forceCreate([
            'name' => 'Test',
            'email' => 'test' . rand() . '@test.com',
            'password' => '123'
        ]);
        $business = BusinessProfile::create(['user_id' => $user->id, 'name' => 'Salla test business']);
        $state = app(OAuthStateService::class)->issue('salla', $user->id, ['business_id' => $business->id]);

        $response = $this->get('/api/channels/callback/salla?error=access_denied&state=' . $state);

        $response->assertRedirect(env('FRONTEND_URL') . '/dashboard/channels?error=salla_denied');
    }

    protected function mockSallaServiceResponses(): void
    {
        \Illuminate\Support\Facades\Http::fake([
            'accounts.salla.sa/oauth2/token' => \Illuminate\Support\Facades\Http::response([
                'access_token' => 'mock_access_token',
                'refresh_token' => 'mock_refresh_token',
                'expires_in' => 3600,
            ], 200),
            
            'accounts.salla.sa/oauth2/user/info' => \Illuminate\Support\Facades\Http::response([
                'data' => [
                    'id' => 12345,
                    'merchant' => [
                        'id' => 99999
                    ],
                    'email' => 'test@example.com',
                    'name' => 'Test User'
                ]
            ], 200),
            
            'api.salla.dev/admin/v2/store/info' => \Illuminate\Support\Facades\Http::response([
                'data' => [
                    'id' => 99999,
                    'name' => 'Test Store'
                ]
            ], 200),
            
            // Catch-all for other endpoints just in case webhooks are registered during callback
            '*' => \Illuminate\Support\Facades\Http::response([], 200),
        ]);
    }
}
