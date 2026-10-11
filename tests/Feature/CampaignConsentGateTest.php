<?php

namespace Tests\Feature;

use App\Jobs\SendCampaignMessage;
use App\Models\BusinessProfile;
use App\Models\Campaign;
use App\Models\CampaignLog;
use App\Models\Channel;
use App\Models\ContactConsent;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Services\CampaignLaunchService;
use App\Services\CampaignAudiencePreviewService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CampaignConsentGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_campaign_audience_preview_reports_sender_and_consent_and_window_block_reasons(): void
    {
        $owner = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $owner->id]);
        $channel = Channel::create([
            'user_id' => $owner->id,
            'business_id' => $business->id,
            'type' => 'instagram',
            'page_id' => 'preview-instagram-page',
            'page_name' => 'Fashion Instagram',
            'access_token' => 'test-token',
            'status' => 'connected',
        ]);

        $eligible = Conversation::create([
            'channel_id' => $channel->id, 'business_id' => $business->id, 'sender_id' => 'eligible-contact',
        ]);
        Message::create(['conversation_id' => $eligible->id, 'content' => 'Hi', 'direction' => 'inbound', 'created_at' => now()]);
        ContactConsent::create([
            'business_id' => $business->id, 'channel' => 'instagram', 'contact_identifier' => 'eligible-contact',
            'scope' => 'marketing', 'status' => 'opted_in', 'source' => 'form', 'captured_at' => now(),
        ]);

        $noConsent = Conversation::create([
            'channel_id' => $channel->id, 'business_id' => $business->id, 'sender_id' => 'no-consent-contact',
        ]);
        Message::create(['conversation_id' => $noConsent->id, 'content' => 'Hi', 'direction' => 'inbound', 'created_at' => now()]);

        $outsideWindow = Conversation::create([
            'channel_id' => $channel->id, 'business_id' => $business->id, 'sender_id' => 'window-contact',
        ]);
        Message::create([
            'conversation_id' => $outsideWindow->id, 'content' => 'Old inquiry', 'direction' => 'inbound', 'created_at' => now()->subHours(25),
        ]);
        ContactConsent::create([
            'business_id' => $business->id, 'channel' => 'instagram', 'contact_identifier' => 'window-contact',
            'scope' => 'marketing', 'status' => 'opted_in', 'source' => 'form', 'captured_at' => now(),
        ]);

        $preview = app(CampaignAudiencePreviewService::class)->preview($channel);

        $this->assertSame('Fashion Instagram', $preview['sender']['account_name']);
        $this->assertSame(3, $preview['total_matched']);
        $this->assertSame(1, $preview['eligible']);
        $this->assertSame(1, $preview['excluded']['no_consent']);
        $this->assertSame(1, $preview['excluded']['messaging_window_expired']);
        $this->assertTrue($preview['campaign_ready']);
        $this->assertTrue($preview['rechecked_at_delivery']);
    }

    public function test_campaign_audience_preview_route_is_tenant_scoped(): void
    {
        $owner = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $owner->id]);
        $otherOwner = User::factory()->create();
        $otherBusiness = BusinessProfile::factory()->create(['user_id' => $otherOwner->id]);
        $channel = Channel::create([
            'user_id' => $owner->id, 'business_id' => $business->id, 'type' => 'telegram',
            'page_id' => 'preview-telegram-account', 'page_name' => 'Support Telegram',
            'access_token' => 'test-token', 'status' => 'connected',
        ]);
        $foreignChannel = Channel::create([
            'user_id' => $otherOwner->id, 'business_id' => $otherBusiness->id, 'type' => 'telegram',
            'page_id' => 'foreign-telegram-account', 'access_token' => 'foreign-token', 'status' => 'connected',
        ]);
        Conversation::create([
            'channel_id' => $channel->id, 'business_id' => $business->id, 'sender_id' => 'preview-contact',
        ]);
        Sanctum::actingAs($owner);

        $this->withHeader('X-Business-Id', (string) $business->id)
            ->postJson("/api/businesses/{$business->id}/campaigns/audience/preview", ['channel_id' => $channel->id])
            ->assertOk()
            ->assertJsonPath('sender.account_name', 'Support Telegram')
            ->assertJsonPath('total_matched', 1)
            ->assertJsonPath('eligible', 0)
            ->assertJsonPath('excluded.no_consent', 1);

        $this->withHeader('X-Business-Id', (string) $business->id)
            ->postJson("/api/businesses/{$business->id}/campaigns/audience/preview", ['channel_id' => $foreignChannel->id])
            ->assertUnprocessable();
    }

    public function test_due_campaign_command_launches_scheduled_campaigns_through_the_campaign_service(): void
    {
        Queue::fake();
        $owner = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $owner->id]);
        $channel = Channel::create([
            'user_id' => $owner->id, 'business_id' => $business->id, 'type' => 'instagram',
            'page_id' => 'scheduled-campaign-page', 'access_token' => 'test-token', 'status' => 'connected',
        ]);
        $conversation = Conversation::create([
            'channel_id' => $channel->id, 'business_id' => $business->id, 'sender_id' => 'scheduled-contact',
        ]);
        $campaign = Campaign::create([
            'business_id' => $business->id, 'channel_id' => $channel->id, 'name' => 'Due now',
            'message' => 'Hello', 'status' => 'scheduled', 'scheduled_at' => now()->subMinute(),
        ]);

        $exitCode = Artisan::call('campaigns:send-due');

        $this->assertSame(0, $exitCode);
        $this->assertSame('sending', $campaign->fresh()->status);
        $this->assertSame(1, $campaign->fresh()->total_recipients);
        $this->assertDatabaseHas('campaign_logs', [
            'campaign_id' => $campaign->id,
            'conversation_id' => $conversation->id,
            'status' => 'queued',
        ]);
        Queue::assertPushed(SendCampaignMessage::class);
    }

    public function test_campaign_launch_service_rejects_channel_owned_by_another_business(): void
    {
        $ownerA = User::factory()->create();
        $ownerB = User::factory()->create();
        $businessA = BusinessProfile::factory()->create(['user_id' => $ownerA->id]);
        $businessB = BusinessProfile::factory()->create(['user_id' => $ownerB->id]);
        $foreignChannel = Channel::create([
            'user_id' => $ownerB->id, 'business_id' => $businessB->id, 'type' => 'instagram',
            'page_id' => 'foreign-campaign-page', 'access_token' => 'foreign-token', 'status' => 'connected',
        ]);
        $campaign = Campaign::create([
            'business_id' => $businessA->id, 'channel_id' => $foreignChannel->id, 'name' => 'Invalid tenant link',
            'message' => 'Must not enqueue', 'status' => 'scheduled', 'scheduled_at' => now()->subMinute(),
        ]);

        $result = app(CampaignLaunchService::class)->launch($businessA->id, $campaign->id, ['scheduled']);

        $this->assertFalse($result['success']);
        $this->assertSame(422, $result['status']);
        $this->assertSame('scheduled', $campaign->fresh()->status);
        $this->assertDatabaseCount('campaign_logs', 0);
    }

    public function test_scheduled_campaign_launch_is_not_an_http_route(): void
    {
        $response = $this->postJson('/api/businesses/1/campaigns/1/launch-scheduled');

        $response->assertNotFound();
    }

    public function test_campaign_job_refuses_a_channel_or_conversation_from_another_business(): void
    {
        Http::fake(['*' => Http::response(['message_id' => 'must-not-send'], 200)]);
        $ownerA = User::factory()->create();
        $ownerB = User::factory()->create();
        $businessA = BusinessProfile::factory()->create(['user_id' => $ownerA->id]);
        $businessB = BusinessProfile::factory()->create(['user_id' => $ownerB->id]);
        $channelB = Channel::create([
            'user_id' => $ownerB->id, 'business_id' => $businessB->id, 'type' => 'instagram',
            'page_id' => 'another-business-page', 'access_token' => 'other-token', 'status' => 'connected',
        ]);
        $conversationB = Conversation::create([
            'channel_id' => $channelB->id, 'business_id' => $businessB->id, 'sender_id' => 'foreign-contact',
        ]);
        $campaignA = Campaign::create([
            'business_id' => $businessA->id, 'channel_id' => $channelB->id, 'name' => 'Wrong tenant',
            'message' => 'Must not send', 'status' => 'sending', 'total_recipients' => 2, 'sent_count' => 1,
        ]);
        $log = CampaignLog::create([
            'campaign_id' => $campaignA->id, 'conversation_id' => $conversationB->id, 'status' => 'queued',
        ]);

        (new SendCampaignMessage($log->id))->handle();

        $this->assertSame('failed', $log->fresh()->status);
        $this->assertStringContainsString('business', strtolower($log->fresh()->error_message));
        $this->assertSame(1, $campaignA->fresh()->failed_count);
        $this->assertSame('partially_sent', $campaignA->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_duplicate_campaign_job_cannot_send_while_first_delivery_is_in_progress(): void
    {
        $owner = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $owner->id]);
        $channel = Channel::create([
            'user_id' => $owner->id, 'business_id' => $business->id, 'type' => 'instagram',
            'page_id' => 'campaign-idempotency-page', 'access_token' => 'test-token', 'status' => 'connected',
        ]);
        $conversation = Conversation::create([
            'channel_id' => $channel->id, 'business_id' => $business->id, 'sender_id' => 'campaign-contact',
        ]);
        Message::create([
            'conversation_id' => $conversation->id, 'content' => 'Question', 'direction' => 'inbound',
            'created_at' => now(),
        ]);
        ContactConsent::create([
            'business_id' => $business->id, 'channel' => 'instagram',
            'contact_identifier' => 'campaign-contact', 'scope' => 'marketing', 'status' => 'opted_in',
            'source' => 'form', 'captured_at' => now(),
        ]);
        $campaign = Campaign::create([
            'business_id' => $business->id, 'channel_id' => $channel->id, 'name' => 'Once only',
            'message' => 'Hello', 'status' => 'sending', 'total_recipients' => 1,
        ]);
        $log = CampaignLog::create([
            'campaign_id' => $campaign->id, 'conversation_id' => $conversation->id, 'status' => 'queued',
        ]);

        $reentered = false;
        Http::fake(function ($request) use ($log, &$reentered) {
            if (!$reentered) {
                $reentered = true;
                (new SendCampaignMessage($log->id))->handle();
            }

            return Http::response(['message_id' => 'meta-message-1'], 200);
        });

        (new SendCampaignMessage($log->id))->handle();

        $this->assertSame(1, Http::recorded()->count());
        $this->assertSame('sent', $log->fresh()->status);
        $this->assertSame('meta-message-1', $log->fresh()->provider_message_id);
        $this->assertSame(1, $campaign->fresh()->sent_count);
    }

    public function test_campaign_rate_limit_requeues_a_fresh_job_without_failing_the_recipient(): void
    {
        config(['queue.default' => 'database']);
        Queue::fake();
        Http::fake();
        $owner = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $owner->id]);
        $channel = Channel::create([
            'user_id' => $owner->id, 'business_id' => $business->id, 'type' => 'instagram',
            'page_id' => 'campaign-rate-limit-page', 'access_token' => 'test-token', 'status' => 'connected',
        ]);
        $conversation = Conversation::create([
            'channel_id' => $channel->id, 'business_id' => $business->id, 'sender_id' => 'rate-limited-contact',
        ]);
        Message::create(['conversation_id' => $conversation->id, 'content' => 'Question', 'direction' => 'inbound']);
        ContactConsent::create([
            'business_id' => $business->id, 'channel' => 'instagram',
            'contact_identifier' => 'rate-limited-contact', 'scope' => 'marketing', 'status' => 'opted_in',
            'source' => 'form', 'captured_at' => now(),
        ]);
        $campaign = Campaign::create([
            'business_id' => $business->id, 'channel_id' => $channel->id, 'name' => 'Rate limited',
            'message' => 'Hello', 'status' => 'sending', 'total_recipients' => 1,
        ]);
        $log = CampaignLog::create([
            'campaign_id' => $campaign->id, 'conversation_id' => $conversation->id, 'status' => 'queued',
        ]);
        Cache::put("campaign_rate_limit:{$channel->id}", 30, 60);

        (new SendCampaignMessage($log->id))->handle();

        $this->assertSame('queued', $log->fresh()->status);
        $this->assertSame(0, $campaign->fresh()->failed_count);
        Http::assertNothingSent();
        Queue::assertPushed(SendCampaignMessage::class, fn ($job) => $job->campaignLogId === $log->id);
    }

    public function test_campaign_send_is_blocked_after_customer_sends_stop(): void
    {
        Http::fake(['*' => Http::response(['messages' => [['id' => 'test-provider-id']]], 200)]);
        $owner = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $owner->id]);
        $channel = Channel::create([
            'user_id' => $owner->id, 'business_id' => $business->id, 'type' => 'whatsapp',
            'page_id' => 'wa-campaign-consent', 'access_token' => 'test-token', 'status' => 'connected',
        ]);
        $conversation = Conversation::create([
            'channel_id' => $channel->id, 'business_id' => $business->id, 'sender_id' => '+15551234567',
        ]);
        Message::create(['conversation_id' => $conversation->id, 'content' => 'STOP', 'direction' => 'inbound']);
        $campaign = Campaign::create([
            'business_id' => $business->id, 'channel_id' => $channel->id, 'name' => 'Offer',
            'message' => 'Buy now', 'status' => 'sending', 'total_recipients' => 1,
        ]);
        $log = CampaignLog::create([
            'campaign_id' => $campaign->id, 'conversation_id' => $conversation->id, 'status' => 'queued',
        ]);

        (new SendCampaignMessage($log->id))->handle();

        $this->assertSame('failed', $log->fresh()->status);
        $this->assertStringContainsString('suppressed', strtolower($log->fresh()->error_message));
        $this->assertSame(0, Message::where('conversation_id', $conversation->id)->where('direction', 'outbound')->count());
        $this->assertSame(1, $campaign->fresh()->failed_count);
    }

    public function test_meta_campaign_send_outside_the_24_hour_window_is_blocked_with_reason(): void
    {
        Http::fake(['*' => Http::response(['message_id' => 'should-not-send'], 200)]);
        $owner = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $owner->id]);
        $channel = Channel::create([
            'user_id' => $owner->id,
            'business_id' => $business->id,
            'type' => 'instagram',
            'page_id' => 'instagram-page',
            'access_token' => 'test-token',
            'status' => 'connected',
        ]);
        $conversation = Conversation::create([
            'channel_id' => $channel->id,
            'business_id' => $business->id,
            'sender_id' => 'instagram-contact-1',
        ]);
        Message::create([
            'conversation_id' => $conversation->id,
            'content' => 'Old inquiry',
            'direction' => 'inbound',
            'created_at' => now()->subHours(25),
        ]);
        ContactConsent::create([
            'business_id' => $business->id,
            'channel' => 'instagram',
            'contact_identifier' => 'instagram-contact-1',
            'scope' => 'marketing',
            'status' => 'opted_in',
            'source' => 'form',
            'captured_at' => now(),
        ]);
        $campaign = Campaign::create([
            'business_id' => $business->id,
            'channel_id' => $channel->id,
            'name' => 'Offer',
            'message' => 'Buy now',
            'status' => 'sending',
            'total_recipients' => 1,
        ]);
        $log = CampaignLog::create([
            'campaign_id' => $campaign->id,
            'conversation_id' => $conversation->id,
            'status' => 'queued',
        ]);

        (new SendCampaignMessage($log->id))->handle();

        $this->assertSame('failed', $log->fresh()->status);
        $this->assertStringContainsString('24-hour', $log->fresh()->error_message);
        $this->assertSame(0, Message::where('conversation_id', $conversation->id)->where('direction', 'outbound')->count());
        $this->assertSame(1, $campaign->fresh()->failed_count);
        Http::assertNothingSent();
    }
}
