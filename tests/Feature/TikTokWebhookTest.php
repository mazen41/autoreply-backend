<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TikTokWebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorization_removed_event_resolves_correct_channel_via_user_openid(): void
    {
        $user = User::factory()->create();

        $channelA = Channel::create([
            'user_id' => $user->id,
            'type' => 'tiktok',
            'page_id' => 'openid_account_a',
            'page_name' => 'Account A',
            'access_token' => 'token_a',
            'status' => 'connected',
        ]);

        $channelB = Channel::create([
            'user_id' => $user->id,
            'type' => 'tiktok',
            'page_id' => 'openid_account_b',
            'page_name' => 'Account B',
            'access_token' => 'token_b',
            'status' => 'connected',
        ]);

        $payload = [
            'client_key' => 'test_client_key',
            'event' => 'authorization.removed',
            'create_time' => now()->timestamp,
            'user_openid' => 'openid_account_b',
            'content' => json_encode(['reason' => 1]),
        ];

        $response = $this->postJson('/api/tiktok/webhook', $payload);

        $response->assertStatus(200);

        $channelA->refresh();
        $channelB->refresh();

        // Only the account named in user_openid is affected.
        $this->assertSame('connected', $channelA->status);
        $this->assertSame('disconnected', $channelB->status);
        $this->assertSame(1, $channelB->metadata['disconnected_reason'] ?? null);
    }

    public function test_unknown_user_openid_does_not_error(): void
    {
        $payload = [
            'client_key' => 'test_client_key',
            'event' => 'authorization.removed',
            'create_time' => now()->timestamp,
            'user_openid' => 'no_such_account',
            'content' => json_encode(['reason' => 1]),
        ];

        $response = $this->postJson('/api/tiktok/webhook', $payload);

        $response->assertStatus(200);
    }
}
