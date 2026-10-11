<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
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

    public function test_telegram_image_is_downloaded_to_private_storage_and_saved_on_message(): void
    {
        Storage::fake('private_media');
        Queue::fake();
        Http::fake([
            'api.telegram.org/bot*/getFile*' => Http::response([
                'ok' => true,
                'result' => ['file_path' => 'photos/customer.jpg', 'file_size' => 12],
            ]),
            'api.telegram.org/file/bot*/*' => Http::response('image-bytes', 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $user = User::factory()->create();
        $bot = $this->makeChannel($user, 'media_bot');
        $response = $this->postJson("/api/telegram/webhook/{$user->id}/{$bot->id}", [
            'message' => [
                'message_id' => 99,
                'chat' => ['id' => 700],
                'from' => ['id' => 700, 'first_name' => 'Test'],
                'photo' => [
                    ['file_id' => 'small-file', 'file_size' => 4],
                    ['file_id' => 'large-file', 'file_size' => 12],
                ],
            ],
        ]);

        $response->assertOk();
        $message = \App\Models\Message::latest('id')->firstOrFail();
        $this->assertSame('image', $message->media_type);
        $this->assertSame('pending', $message->media_processing_status);
        $this->assertSame('private_media', $message->metadata['media']['storage_disk']);
        Storage::disk('private_media')->assertExists($message->media_url);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/getFile')
            && $request['file_id'] === 'large-file');
    }

    public function test_telegram_media_download_failure_still_creates_a_visible_failed_media_message(): void
    {
        Queue::fake();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false], 502)]);

        $user = User::factory()->create();
        $bot = $this->makeChannel($user, 'failed_media_bot');
        $this->postJson("/api/telegram/webhook/{$user->id}/{$bot->id}", [
            'message' => [
                'message_id' => 100,
                'chat' => ['id' => 701],
                'from' => ['id' => 701, 'first_name' => 'Test'],
                'document' => ['file_id' => 'unavailable', 'mime_type' => 'application/pdf', 'file_name' => 'address.pdf'],
            ],
        ])->assertOk();

        $message = \App\Models\Message::latest('id')->firstOrFail();
        $this->assertSame('document', $message->media_type);
        $this->assertSame('failed', $message->media_processing_status);
        $this->assertNotEmpty($message->media_processing_reason);
        $this->assertSame('', $message->content);
        $this->assertSame('document', $message->metadata['attachment']['type'] ?? null);
        $this->assertSame('attachment_without_text', $message->metadata['attachment']['status'] ?? null);
    }
}
