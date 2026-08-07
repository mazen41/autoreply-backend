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
        $user = User::factory()->create();
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
        $user = User::factory()->create();
        
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
        $user = User::factory()->create();

        $response = $this->get('/api/channels/callback/salla?error=access_denied&state=' . $user->id . ':dashboard');

        $response->assertRedirect(env('FRONTEND_URL') . '/dashboard/channels?error=salla_denied');
    }

    protected function mockSallaServiceResponses(): void
    {
        // This would typically use mocking to simulate Salla API responses
        // For now, we'll skip actual implementation
    }
}
