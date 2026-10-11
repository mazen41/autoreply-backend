<?php

namespace Tests\Feature;

use App\Models\BusinessProfile;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class MessageAuditLogTest extends TestCase
{
    use RefreshDatabase;

    private function conversation(): array
    {
        $user = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $user->id]);
        $channel = Channel::factory()->create([
            'user_id' => $user->id, 'business_id' => $business->id,
            'type' => 'facebook', 'page_id' => 'PAGE-AUDIT', 'status' => 'connected',
        ]);
        $conversation = Conversation::factory()->create([
            'business_id' => $business->id, 'channel_id' => $channel->id,
        ]);

        return [$user, $conversation];
    }

    public function test_merchant_message_is_logged_with_actor_and_redacted_preview(): void
    {
        [$user, $conversation] = $this->conversation();
        Auth::login($user);
        Log::spy();

        Message::factory()->create([
            'conversation_id' => $conversation->id,
            'direction' => 'outbound',
            'is_ai' => false,
            'source' => 'manual',
            'content' => 'Call +201001234567 or mail sales@example.test about it',
        ]);

        Log::shouldHaveReceived('info')->with('Message audit: created', \Mockery::on(function (array $context) use ($user) {
            return $context['actor'] === 'merchant:' . $user->id
                && $context['direction'] === 'outbound'
                && str_contains($context['preview'], '[phone]')
                && str_contains($context['preview'], '[email]')
                && ! str_contains($context['preview'], '+201001234567')
                && ! str_contains($context['preview'], 'sales@example.test');
        }))->once();
    }

    public function test_ai_reply_is_logged_with_intent_and_confidence(): void
    {
        [, $conversation] = $this->conversation();
        Log::spy();

        Message::factory()->create([
            'conversation_id' => $conversation->id,
            'direction' => 'outbound',
            'is_ai' => true,
            'content' => 'Our shoes start at 500 EGP.',
            'intent' => 'question',
            'confidence_score' => 95,
        ]);

        Log::shouldHaveReceived('info')->with('Message audit: created', \Mockery::on(function (array $context) {
            return $context['actor'] === 'ai'
                && $context['is_ai'] === true
                && $context['intent'] === 'question'
                && (float) $context['confidence_score'] === 95.0;
        }))->once();
    }

    public function test_preview_is_omitted_when_disabled_but_metadata_remains(): void
    {
        config(['message_audit.preview_chars' => 0]);
        [, $conversation] = $this->conversation();
        Log::spy();

        Message::factory()->create([
            'conversation_id' => $conversation->id,
            'direction' => 'outbound',
            'is_ai' => true,
            'content' => 'secret text',
        ]);

        Log::shouldHaveReceived('info')->with('Message audit: created', \Mockery::on(function (array $context) {
            return $context['preview'] === null && $context['content_length'] === 11;
        }))->once();
    }

    public function test_status_change_is_logged_with_from_and_to(): void
    {
        [, $conversation] = $this->conversation();
        $message = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'direction' => 'outbound',
            'is_ai' => true,
            'send_status' => 'queued',
        ]);
        Log::spy();

        $message->update(['send_status' => 'sent']);

        Log::shouldHaveReceived('info')->with('Message audit: status changed', \Mockery::on(function (array $context) {
            return $context['changes']['send_status']['from'] === 'queued'
                && $context['changes']['send_status']['to'] === 'sent';
        }))->once();
    }
}