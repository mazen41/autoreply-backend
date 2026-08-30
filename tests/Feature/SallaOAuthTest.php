<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

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
        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this->get("/api/channels/connect/salla?token={$token}&redirect=dashboard");

        $response->assertRedirect();
        $this->assertStringContainsString('accounts.salla.sa', $response->getTargetUrl());
    }

    public function test_salla_connect_without_token_fails(): void
    {
        $response = $this->get('/api/channels/connect/salla');

        $response->assertRedirect(env('FRONTEND_URL') . '/dashboard/channels?error=unauthorized');
    }

    public function test_salla_callback_creates_channel(): void
    {
        $user = User::forceCreate([
            'name' => 'Test',
            'email' => 'test' . rand() . '@test.com',
            'password' => '123'
        ]);
        
        // Mock the Salla service responses
        $this->mockSallaServiceResponses();

        $response = $this->get('/api/channels/callback/salla?code=test_code&state=' . $user->id . ':dashboard');

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

        $response = $this->get('/api/channels/callback/salla?error=access_denied&state=' . $user->id . ':dashboard');

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
