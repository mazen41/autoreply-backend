<?php

namespace Tests\Feature;

use App\Models\BusinessProfile;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\GreetingRepeatGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GreetingRepeatGuardTest extends TestCase
{
    use RefreshDatabase;

    private function conversation(): Conversation
    {
        $user = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $user->id]);
        $channel = Channel::factory()->create([
            'user_id' => $user->id, 'business_id' => $business->id,
            'type' => 'instagram', 'status' => 'connected',
        ]);

        return Conversation::factory()->create(['business_id' => $business->id, 'channel_id' => $channel->id]);
    }

    private function inbound(Conversation $c, string $text): Message
    {
        return Message::factory()->create([
            'conversation_id' => $c->id, 'direction' => 'inbound', 'is_ai' => false, 'content' => $text,
        ]);
    }

    private function aiReply(Conversation $c, string $text): void
    {
        Message::factory()->create([
            'conversation_id' => $c->id, 'direction' => 'outbound', 'is_ai' => true, 'content' => $text,
        ]);
    }

    private const WELCOME = 'Hi 👋 Welcome to Naz Autoreply! How can I help you today?';

    public function test_first_greeting_keeps_the_welcome_message(): void
    {
        $c = $this->conversation();
        $message = $this->inbound($c, 'hi');

        $result = app(GreetingRepeatGuard::class)->apply($message, self::WELCOME);

        $this->assertFalse($result['replaced']);
        $this->assertSame(self::WELCOME, $result['reply']);
    }

    public function test_repeated_greeting_does_not_receive_the_welcome_again(): void
    {
        $c = $this->conversation();
        $this->inbound($c, 'hi');
        $this->aiReply($c, self::WELCOME);
        $this->inbound($c, 'hello');
        $this->aiReply($c, 'Hello! Happy to help. What are you looking for?');
        $message = $this->inbound($c, 'hi');

        $result = app(GreetingRepeatGuard::class)->apply($message, self::WELCOME);

        $this->assertTrue($result['replaced']);
        $this->assertNotSame(self::WELCOME, $result['reply']);
        $this->assertStringNotContainsString('Welcome to Naz', $result['reply']);
        $this->assertSame(2, $result['previous_greetings']);
    }

    public function test_repeated_greeting_in_arabic_gets_an_arabic_reply(): void
    {
        $c = $this->conversation();
        $this->inbound($c, 'hi');
        $this->aiReply($c, self::WELCOME);
        $message = $this->inbound($c, 'اهلا');

        $result = app(GreetingRepeatGuard::class)->apply($message, self::WELCOME);

        $this->assertTrue($result['replaced']);
        $this->assertMatchesRegularExpression('/\p{Arabic}/u', $result['reply']);
    }

    public function test_repeated_identical_reply_is_replaced_even_without_welcome_text(): void
    {
        $c = $this->conversation();
        $this->inbound($c, 'hi');
        $this->aiReply($c, 'Sure, what do you need?');
        $message = $this->inbound($c, 'hi');

        $result = app(GreetingRepeatGuard::class)->apply($message, 'Sure, what do you need?');

        $this->assertTrue($result['replaced']);
    }

    public function test_non_greeting_messages_are_never_changed(): void
    {
        $c = $this->conversation();
        $this->inbound($c, 'hi');
        $this->aiReply($c, self::WELCOME);
        $message = $this->inbound($c, 'show me the snowboards');

        $result = app(GreetingRepeatGuard::class)->apply($message, self::WELCOME);

        $this->assertFalse($result['replaced']);
    }
}