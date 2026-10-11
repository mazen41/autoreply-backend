<?php

namespace Tests\Feature;

use App\Models\BusinessProfile;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\EmailCampaign;
use App\Models\EmailCampaignRecipient;
use App\Models\Package;
use App\Models\Subscription;
use App\Models\TeamMember;
use App\Models\User;
use App\Models\WhatsAppInstance;
use App\Jobs\RetryFailedMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BusinessUsageMeterTest extends TestCase
{
    use RefreshDatabase;

    public function test_usage_endpoint_reports_monthly_business_scoped_ai_replies_outbound_messages_and_channels(): void
    {
        $owner = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $owner->id]);
        $owner->update(['business_id' => $business->id]);
        $package = Package::create([
            'name' => 'Usage test', 'name_ar' => 'اختبار', 'price_monthly' => 0, 'price_yearly' => 0,
            'ai_replies_limit' => 5, 'channels_limit' => 3, 'outbound_messages_limit' => 10,
            'is_active' => true,
        ]);
        Subscription::create([
            'user_id' => $owner->id, 'business_id' => $business->id, 'package_id' => $package->id,
            'status' => 'active', 'billing_cycle' => 'monthly', 'amount_paid' => 0,
            'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth(),
        ]);
        $channel = Channel::create([
            'user_id' => $owner->id, 'business_id' => $business->id,
            'type' => 'instagram', 'page_id' => 'usage-channel', 'access_token' => 'test-token', 'status' => 'connected',
        ]);
        $conversation = Conversation::create([
            'business_id' => $business->id, 'channel_id' => $channel->id, 'sender_id' => 'usage-contact',
        ]);
        Message::create([
            'conversation_id' => $conversation->id, 'content' => 'AI reply', 'direction' => 'outbound',
            'is_ai' => true, 'status' => 'sent', 'send_status' => 'sent', 'delivery_status' => 'sent',
        ]);
        Message::create([
            'conversation_id' => $conversation->id, 'content' => 'Human reply queued', 'direction' => 'outbound',
            'is_ai' => false, 'status' => 'queued', 'send_status' => 'queued',
        ]);
        Message::create([
            'conversation_id' => $conversation->id, 'content' => 'Rejected reply', 'direction' => 'outbound',
            'is_ai' => true, 'status' => 'failed', 'send_status' => 'failed', 'delivery_status' => 'failed',
        ]);
        Message::create([
            'conversation_id' => $conversation->id, 'content' => 'Inbound', 'direction' => 'inbound',
            'is_ai' => false, 'status' => 'received', 'send_status' => 'received',
        ]);
        $emailCampaign = EmailCampaign::create([
            'business_id' => $business->id,
            'name' => 'Usage email', 'subject' => 'Update', 'content' => 'Hello', 'status' => 'sent',
        ]);
        EmailCampaignRecipient::create([
            'email_campaign_id' => $emailCampaign->id, 'email' => 'contact@example.test',
            'status' => 'sent', 'sent_at' => now(),
        ]);

        $response = $this->actingAs($owner)
            ->withHeader('X-Business-Id', (string) $business->id)
            ->getJson('/api/businesses/' . $business->id . '/usage');

        $response->assertOk()
            ->assertJsonPath('business_id', $business->id)
            ->assertJsonPath('plan.name', 'Usage test')
            ->assertJsonPath('usage.ai_replies.used', 1)
            ->assertJsonPath('usage.ai_replies.limit', 5)
            ->assertJsonPath('usage.outbound_messages.used', 3)
            ->assertJsonPath('usage.outbound_messages.limit', 10)
            ->assertJsonPath('usage.channels.used', 1)
            ->assertJsonPath('usage.channels.limit', 3);
    }

    public function test_channel_connection_limit_uses_selected_business_owner_plan_for_a_team_member(): void
    {
        Http::fake();
        $owner = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $owner->id]);
        $owner->update(['business_id' => $business->id]);
        $member = User::factory()->create(['business_id' => null]);
        TeamMember::create([
            'business_id' => $business->id, 'user_id' => $member->id,
            'role' => 'manager', 'is_active' => true,
        ]);
        $package = Package::create([
            'name' => 'No channel plan', 'name_ar' => 'بدون', 'price_monthly' => 0, 'price_yearly' => 0,
            'ai_replies_limit' => 10, 'channels_limit' => 0, 'outbound_messages_limit' => 10,
            'is_active' => true,
        ]);
        Subscription::create([
            'user_id' => $owner->id, 'business_id' => $business->id, 'package_id' => $package->id,
            'status' => 'active', 'billing_cycle' => 'monthly', 'amount_paid' => 0,
            'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth(),
        ]);

        $response = $this->actingAs($member)
            ->withHeader('X-Business-Id', (string) $business->id)
            ->postJson('/api/channels/whatsapp/cloud', [
                'phone_number_id' => 'phone-limit-test',
                'business_account_id' => 'waba-limit-test',
                'access_token' => 'a-test-token-with-more-than-twenty-chars',
            ]);

        $response->assertForbidden()->assertJsonPath('error_code', 'PLAN_LIMIT_EXCEEDED');
        Http::assertNothingSent();
    }

    public function test_negative_channel_limit_is_unlimited_in_connection_middleware(): void
    {
        $owner = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $owner->id]);
        $owner->update(['business_id' => $business->id]);
        $package = Package::create([
            'name' => 'Unlimited channels', 'name_ar' => 'غير محدود', 'price_monthly' => 0, 'price_yearly' => 0,
            'ai_replies_limit' => -1, 'channels_limit' => -1, 'outbound_messages_limit' => -1,
            'is_active' => true,
        ]);
        Subscription::create([
            'user_id' => $owner->id, 'business_id' => $business->id, 'package_id' => $package->id,
            'status' => 'active', 'billing_cycle' => 'monthly', 'amount_paid' => 0,
            'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth(),
        ]);

        $this->actingAs($owner)
            ->withHeader('X-Business-Id', (string) $business->id)
            ->postJson('/api/channels/connect/facebook')
            ->assertOk()
            ->assertJsonStructure(['url']);
    }

    public function test_legacy_whatsapp_direct_send_is_blocked_by_selected_business_monthly_limit(): void
    {
        Http::fake();
        $owner = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $owner->id]);
        $owner->update(['business_id' => $business->id]);
        $package = Package::create([
            'name' => 'No outbound', 'name_ar' => 'بدون إرسال', 'price_monthly' => 0, 'price_yearly' => 0,
            'ai_replies_limit' => 10, 'channels_limit' => 10, 'outbound_messages_limit' => 0,
            'is_active' => true,
        ]);
        Subscription::create([
            'user_id' => $owner->id, 'business_id' => $business->id, 'package_id' => $package->id,
            'status' => 'active', 'billing_cycle' => 'monthly', 'amount_paid' => 0,
            'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth(),
        ]);
        $instance = WhatsAppInstance::create([
            'user_id' => $owner->id, 'business_id' => $business->id,
            'instance_name' => 'legacy-limit-instance', 'status' => 'connected',
            'metadata' => ['unofficial_risk_accepted' => true],
        ]);
        Channel::create([
            'user_id' => $owner->id, 'business_id' => $business->id, 'type' => 'whatsapp',
            'page_id' => $instance->instance_name, 'access_token' => '', 'status' => 'connected',
            'metadata' => ['whatsapp_provider' => 'evolution', 'unofficial_risk_accepted' => true],
        ]);

        $this->actingAs($owner)
            ->withHeader('X-Business-Id', (string) $business->id)
            ->postJson('/api/whatsapp/send', [
                'instance_name' => $instance->instance_name,
                'number' => '15555550123',
                'message' => 'Plan limited message',
            ])
            ->assertForbidden()
            ->assertJsonPath('error_code', 'PLAN_LIMIT_EXCEEDED');

        $this->assertDatabaseCount('messages', 0);
        Http::assertNothingSent();
    }

    public function test_failed_message_retry_cannot_bypass_business_outbound_limit(): void
    {
        Http::fake();
        $owner = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $owner->id]);
        $package = Package::create([
            'name' => 'Retry limit', 'name_ar' => 'إعادة محدودة', 'price_monthly' => 0, 'price_yearly' => 0,
            'ai_replies_limit' => 10, 'channels_limit' => 10, 'outbound_messages_limit' => 0,
            'is_active' => true,
        ]);
        Subscription::create([
            'user_id' => $owner->id, 'business_id' => $business->id, 'package_id' => $package->id,
            'status' => 'active', 'billing_cycle' => 'monthly', 'amount_paid' => 0,
            'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth(),
        ]);
        $channel = Channel::create([
            'user_id' => $owner->id, 'business_id' => $business->id, 'type' => 'instagram',
            'page_id' => 'retry-limit-channel', 'access_token' => 'secret', 'status' => 'connected',
        ]);
        $conversation = Conversation::create([
            'business_id' => $business->id, 'channel_id' => $channel->id, 'sender_id' => 'retry-contact',
        ]);
        $failed = Message::create([
            'conversation_id' => $conversation->id, 'content' => 'Retry me', 'direction' => 'outbound',
            'status' => 'failed', 'send_status' => 'failed', 'delivery_status' => 'failed', 'is_ai' => false,
        ]);

        (new RetryFailedMessage($failed->id))->handle();

        $this->assertSame('blocked', $failed->fresh()->send_status);
        Http::assertNothingSent();
    }
}
