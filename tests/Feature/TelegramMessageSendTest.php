<?php

namespace Tests\Feature;

use App\Models\BusinessProfile;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\ChannelMessageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramMessageSendTest extends TestCase
{
    use RefreshDatabase;

    public function test_telegram_send_uses_the_channel_model_decrypted_access_token_once(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        $user = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $user->id]);
        $channel = Channel::create([
            'user_id' => $user->id,
            'business_id' => $business->id,
            'type' => 'telegram',
            'page_id' => 'test-bot',
            'page_name' => 'Test Telegram bot',
            'access_token' => '123456:test-token',
            'status' => 'connected',
            'ai_enabled' => true,
        ]);
        $conversation = Conversation::create([
            'business_id' => $business->id,
            'channel_id' => $channel->id,
            'sender_id' => 'chat-123',
            'status' => 'open',
        ]);
        $message = Message::create([
            'conversation_id' => $conversation->id,
            'content' => 'Hello from Naz',
            'direction' => 'outbound',
            'status' => 'auto',
            'is_ai' => true,
            'send_status' => 'pending',
        ]);

        $sent = app(ChannelMessageService::class)->sendMessage($channel, $conversation, $message);

        $this->assertTrue($sent);
        $this->assertSame('sent', $message->fresh()->send_status);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/bot123456:test-token/sendMessage')
            && $request['chat_id'] === 'chat-123'
            && $request['text'] === 'Hello from Naz');
    }
}
