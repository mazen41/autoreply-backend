<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ChannelsEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_channels_index_returns_authenticated_users_channels(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        Channel::create([
            'user_id' => $user->id,
            'type' => 'facebook',
            'page_id' => 'page-1',
            'page_name' => 'Main Page',
            'access_token' => 'secret-token',
            'status' => 'connected',
            'connected_at' => now(),
            'ai_enabled' => true,
        ]);

        Channel::create([
            'user_id' => $otherUser->id,
            'type' => 'gmail',
            'page_id' => 'mailbox-1',
            'page_name' => 'Other Mailbox',
            'access_token' => 'other-secret-token',
            'status' => 'connected',
            'connected_at' => now(),
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/channels')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type', 'facebook')
            ->assertJsonPath('data.0.page_name', 'Main Page')
            ->assertJsonMissing(['access_token' => 'secret-token']);
    }
}
