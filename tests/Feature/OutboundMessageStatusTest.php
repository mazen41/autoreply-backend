<?php

namespace Tests\Feature;

use App\Models\BusinessProfile;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\ChannelMessageService;
use App\Services\MetaMessengerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OutboundMessageStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_meta_rejection_marks_workflow_message_failed_instead_of_sent(): void
    {
        $user = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $user->id]);
        $channel = Channel::factory()->create([
            'user_id' => $user->id,
            'business_id' => $business->id,
            'type' => 'facebook',
            'status' => 'connected',
            'access_token' => 'test-meta-token',
        ]);
        $conversation = Conversation::factory()->create([
            'business_id' => $business->id,
            'channel_id' => $channel->id,
            'sender_id' => 'meta-recipient',
        ]);
        $message = Message::create([
            'conversation_id' => $conversation->id,
            'content' => 'Hello',
            'direction' => 'outbound',
            'status' => 'pending',
            'send_status' => 'pending',
            'delivery_status' => 'pending',
        ]);
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'blocked']], 400)]);

        $sent = app(ChannelMessageService::class)->sendMessage($channel, $conversation, $message);

        $this->assertFalse($sent);
        $this->assertSame('failed', $message->fresh()->status);
        $this->assertSame('failed', $message->fresh()->send_status);
        $this->assertSame('failed', $message->fresh()->delivery_status);
        $this->assertNotEmpty($message->fresh()->error_details);
    }

    public function test_meta_image_rejection_is_not_reported_as_a_complete_success(): void
    {
        Http::fakeSequence()
            ->push(['error' => ['message' => 'image rejected']], 400)
            ->push(['message_id' => 'text-accepted'], 200);
        $channel = new Channel(['type' => 'instagram', 'access_token' => 'test-token']);

        $result = app(MetaMessengerService::class)->sendTextWithResult(
            $channel,
            'recipient',
            'Here is the image',
            ['https://example.test/product.jpg'],
        );

        $this->assertFalse($result['success']);
        $this->assertSame('text-accepted', $result['message_id']);
        $this->assertSame('image rejected', $result['error']);
    }

    public function test_unsupported_channel_send_is_recorded_as_failed_not_pending(): void
    {
        $user = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $user->id]);
        $channel = Channel::factory()->create([
            'user_id' => $user->id,
            'business_id' => $business->id,
            'type' => 'web_chat',
            'status' => 'connected',
        ]);
        $conversation = Conversation::factory()->create([
            'business_id' => $business->id,
            'channel_id' => $channel->id,
            'sender_id' => 'webchat-customer',
        ]);
        $message = Message::create([
            'conversation_id' => $conversation->id,
            'content' => 'Hello',
            'direction' => 'outbound',
            'status' => 'pending',
            'send_status' => 'pending',
            'delivery_status' => 'pending',
        ]);

        $sent = app(ChannelMessageService::class)->sendMessage($channel, $conversation, $message);

        $this->assertFalse($sent);
        $this->assertSame('failed', $message->fresh()->status);
        $this->assertSame('failed', $message->fresh()->delivery_status);
        $this->assertSame('unsupported_channel_type', $message->fresh()->error_details);
    }
}
