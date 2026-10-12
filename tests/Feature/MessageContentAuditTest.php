<?php

namespace Tests\Feature;

use App\Models\BusinessProfile;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class MessageContentAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_content_changed_after_creation_is_logged_by_hash_not_text(): void
    {
        $user = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $user->id]);
        $channel = Channel::factory()->create(['user_id' => $user->id, 'business_id' => $business->id, 'type' => 'facebook', 'status' => 'connected']);
        $conversation = Conversation::factory()->create(['business_id' => $business->id, 'channel_id' => $channel->id]);
        $message = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'direction' => 'outbound',
            'is_ai' => true,
            'content' => 'Your order has been placed.',
        ]);
        Log::spy();

        $message->update(['content' => 'I have not submitted the order yet.']);

        Log::shouldHaveReceived('info')->with('Message audit: status changed', \Mockery::on(function (array $context) {
            return isset($context['changes']['content'])
                && $context['changes']['content']['from_hash'] !== $context['changes']['content']['to_hash']
                && ! str_contains(json_encode($context), 'has been placed')
                && ! str_contains(json_encode($context), 'not submitted');
        }))->once();
    }
}