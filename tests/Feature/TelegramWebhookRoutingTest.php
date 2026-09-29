<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TelegramWebhookRoutingTest extends TestCase
{
    use RefreshDatabase;

    private function makeChannel(User $user, string $botUsername): Channel
    {
        return Channel::create([
            'user_id' => $user->id,
            'type' => 'telegram',
            'page_id' => $botUsername,
            'page_name' => $botUsername,
            'access_token' => 'token_for_' . $botUsername,
            'status' => 'connected',
        ]);
    }

    private function telegramMessagePayload(int $chatId, string $text): array
    {
        return [
            'message' => [
                'message_id' => 1,
                'chat' => ['id' => $chatId],
                'text' => $text,
                'from' => ['id' => $chatId, 'first_name' => 'Test'],
            ],
        ];
    }

    public function test_new_url_routes_to_exact_channel_when_user_has_multiple_bots(): void
    {
        $user = User::factory()->create();
        $botA = $this->makeChannel($user, 'bot_a');
        $botB = $this->makeChannel($user, 'bot_b');

        $response = $this->postJson(
            "/api/telegram/webhook/{$user->id}/{$botB->id}",
            $this->telegramMessagePayload(555, 'hi from bot b')
        );

        $response->assertStatus(200);

        $this->assertDatabaseHas('conversations', [
            'channel_id' => $botB->id,
            'sender_id' => '555',
        ]);
        $this->assertDatabaseMissing('conversations', [
            'channel_id' => $botA->id,
            'sender_id' => '555',
        ]);
    }

    public function test_legacy_url_still_works_when_user_has_exactly_one_bot(): void
    {
        $user = User::factory()->create();
        $bot = $this->makeChannel($user, 'only_bot');

        $response = $this->postJson(
            "/api/telegram/webhook/{$user->id}",
            $this->telegramMessagePayload(777, 'hi single bot')
        );

        $response->assertStatus(200);

        $this->assertDatabaseHas('conversations', [
            'channel_id' => $bot->id,
            'sender_id' => '777',
        ]);
    }

    public function test_legacy_url_refuses_to_guess_when_user_has_multiple_bots(): void
    {
        $user = User::factory()->create();
        $this->makeChannel($user, 'bot_a');
        $this->makeChannel($user, 'bot_b');

        $response = $this->postJson(
            "/api/telegram/webhook/{$user->id}",
            $this->telegramMessagePayload(999, 'ambiguous message')
        );

        $response->assertStatus(200);

        // No conversation should be created anywhere — must not guess.
        $this->assertDatabaseMissing('conversations', [
            'sender_id' => '999',
        ]);
    }
}
