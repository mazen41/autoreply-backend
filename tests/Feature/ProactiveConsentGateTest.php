<?php

namespace Tests\Feature;

use App\Jobs\SendProactiveMessage;
use App\Models\BusinessProfile;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\ContactConsent;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProactiveConsentGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_proactive_marketing_message_is_not_sent_without_marketing_consent(): void
    {
        Http::fake();

        $owner = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $owner->id]);
        $channel = Channel::create([
            'user_id' => $owner->id,
            'business_id' => $business->id,
            'type' => 'whatsapp',
            'page_id' => 'wa-proactive-consent',
            'access_token' => 'test-token',
            'status' => 'connected',
        ]);
        $conversation = Conversation::create([
            'business_id' => $business->id,
            'channel_id' => $channel->id,
            'sender_id' => '+15551234567',
        ]);
        $campaign = \App\Models\ProactiveCampaign::create([
            'user_id' => $owner->id,
            'name' => 'Offer',
            'message' => 'Buy now',
            'scheduled_for' => now()->addHour(),
            'status' => 'scheduled',
            'segment_config' => [],
        ]);

        (new SendProactiveMessage($campaign->id, $conversation->id))->handle();

        $blocked = Message::where('conversation_id', $conversation->id)->where('direction', 'outbound')->firstOrFail();
        $this->assertSame('blocked', $blocked->send_status);
        $this->assertStringContainsString('consent', strtolower($blocked->error_details));
        Http::assertNothingSent();
    }

    public function test_opted_in_contact_still_requires_approved_cloud_template_for_proactive_whatsapp(): void
    {
        Http::fake(['*' => Http::response(['key' => ['id' => 'provider-message-id']], 200)]);

        $owner = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $owner->id]);
        $channel = Channel::create([
            'user_id' => $owner->id,
            'business_id' => $business->id,
            'type' => 'whatsapp',
            'page_id' => 'wa-proactive-consent',
            'access_token' => 'test-token',
            'status' => 'connected',
            'metadata' => ['whatsapp_provider' => 'evolution', 'unofficial_risk_accepted' => true],
        ]);
        $conversation = Conversation::create([
            'business_id' => $business->id,
            'channel_id' => $channel->id,
            'sender_id' => '+15551234567',
        ]);
        ContactConsent::create([
            'business_id' => $business->id,
            'channel' => 'whatsapp',
            'contact_identifier' => '+15551234567',
            'scope' => 'marketing',
            'status' => 'opted_in',
            'source' => 'form',
            'captured_at' => now(),
        ]);
        $campaign = \App\Models\ProactiveCampaign::create([
            'user_id' => $owner->id,
            'name' => 'Offer',
            'message' => 'Buy now',
            'scheduled_for' => now()->addHour(),
            'status' => 'scheduled',
            'segment_config' => [],
        ]);

        (new SendProactiveMessage($campaign->id, $conversation->id))->handle();

        $this->assertSame(1, Message::where('conversation_id', $conversation->id)->where('direction', 'outbound')->count());
        $this->assertSame('blocked', Message::where('conversation_id', $conversation->id)->where('direction', 'outbound')->value('send_status'));
        $this->assertStringContainsString('approved Cloud API template', Message::where('conversation_id', $conversation->id)->where('direction', 'outbound')->value('error_details'));
        Http::assertNothingSent();
    }

    public function test_proactive_gmail_without_a_configured_provider_client_is_not_marked_sent(): void
    {
        Http::fake();
        $owner = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $owner->id]);
        $channel = Channel::create([
            'user_id' => $owner->id,
            'business_id' => $business->id,
            'type' => 'gmail',
            'page_id' => 'sender@example.test',
            'access_token' => 'invalid-gmail-token-payload',
            'status' => 'connected',
        ]);
        $conversation = Conversation::create([
            'business_id' => $business->id,
            'channel_id' => $channel->id,
            'sender_id' => 'gmail-thread-1',
            'sender_email' => 'recipient@example.test',
        ]);
        ContactConsent::create([
            'business_id' => $business->id,
            'channel' => 'gmail',
            'contact_identifier' => 'recipient@example.test',
            'scope' => 'marketing',
            'status' => 'opted_in',
            'source' => 'form',
            'captured_at' => now(),
        ]);
        $campaign = \App\Models\ProactiveCampaign::create([
            'user_id' => $owner->id,
            'name' => 'Offer',
            'message' => 'Buy now',
            'scheduled_for' => now()->addHour(),
            'status' => 'scheduled',
            'segment_config' => [],
        ]);

        (new SendProactiveMessage($campaign->id, $conversation->id))->handle();

        $message = Message::where('conversation_id', $conversation->id)->where('direction', 'outbound')->firstOrFail();
        $this->assertSame('failed', $message->send_status);
        $this->assertSame('failed', $message->delivery_status);
        $this->assertNotSame('sent', $message->status);
        Http::assertNothingSent();
    }
}
