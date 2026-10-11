<?php

namespace Tests\Feature;

use App\Models\BusinessProfile;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\ContactConsent;
use App\Models\Message;
use App\Models\Suppression;
use App\Models\User;
use App\Services\ConsentGate;
use App\Services\EmailCampaignAudienceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContactConsentGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_marketing_requires_opt_in_and_suppression_always_blocks(): void
    {
        $business = BusinessProfile::factory()->create();
        $gate = app(ConsentGate::class);
        $this->assertFalse($gate->check($business, 'alice@example.test', 'email', 'marketing')->allowed);
        ContactConsent::updateOrCreate(
            ['business_id' => $business->id, 'channel' => 'email', 'contact_identifier' => 'alice@example.test', 'scope' => 'marketing'],
            ['status' => 'opted_in', 'source' => 'admin', 'captured_at' => now()]
        );
        $this->assertTrue($gate->check($business, 'ALICE@example.test', 'email', 'marketing')->allowed);
        Suppression::create([
            'business_id' => $business->id, 'channel' => 'email', 'contact_identifier' => 'alice@example.test',
            'reason' => 'complained', 'source' => 'admin', 'suppressed_at' => now(),
        ]);
        $decision = $gate->check($business, 'alice@example.test', 'email', 'marketing');
        $this->assertFalse($decision->allowed);
        $this->assertSame('suppressed', $decision->reason);
    }

    public function test_service_scope_is_allowed_unless_contact_is_opted_out_or_suppressed(): void
    {
        $business = BusinessProfile::factory()->create();
        $gate = app(ConsentGate::class);
        $this->assertTrue($gate->check($business, '12345', 'whatsapp', 'service')->allowed);
        ContactConsent::create([
            'business_id' => $business->id, 'channel' => 'whatsapp', 'contact_identifier' => '12345',
            'scope' => 'service', 'status' => 'opted_out', 'source' => 'inbound_keyword', 'captured_at' => now(),
        ]);
        $this->assertFalse($gate->check($business, '12345', 'whatsapp', 'service')->allowed);
    }

    public function test_gmail_conversation_uses_sender_email_and_email_consent_channel(): void
    {
        $business = BusinessProfile::factory()->create();
        $channel = Channel::factory()->create(['business_id' => $business->id, 'type' => 'gmail']);
        $conversation = Conversation::factory()->create([
            'business_id' => $business->id,
            'channel_id' => $channel->id,
            'sender_id' => 'gmail-thread-123',
            'sender_email' => 'Reader@Example.test',
        ]);
        ContactConsent::create([
            'business_id' => $business->id,
            'channel' => 'email',
            'contact_identifier' => 'reader@example.test',
            'scope' => 'marketing',
            'status' => 'opted_in',
            'source' => 'form',
            'captured_at' => now(),
        ]);

        $this->assertTrue(app(ConsentGate::class)->checkConversation($conversation)->allowed);
    }

    public function test_inbound_stop_and_start_keywords_update_consent_and_hard_suppression(): void
    {
        $owner = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $owner->id]);
        $channel = Channel::create([
            'user_id' => $owner->id, 'business_id' => $business->id, 'type' => 'whatsapp',
            'page_id' => 'wa-consent-test', 'access_token' => 'test-token', 'status' => 'connected',
        ]);
        $conversation = Conversation::create([
            'channel_id' => $channel->id, 'business_id' => $business->id, 'sender_id' => '+15551234567',
        ]);

        Message::create(['conversation_id' => $conversation->id, 'content' => 'STOP', 'direction' => 'inbound']);
        $consent = ContactConsent::where('business_id', $business->id)->where('contact_identifier', '+15551234567')->firstOrFail();
        $this->assertSame('opted_out', $consent->status);
        $this->assertDatabaseHas('suppressions', [
            'business_id' => $business->id, 'contact_identifier' => '+15551234567', 'reason' => 'unsubscribed',
        ]);

        Message::create(['conversation_id' => $conversation->id, 'content' => 'START', 'direction' => 'inbound']);
        $this->assertSame('opted_in', $consent->fresh()->status);
        $this->assertDatabaseMissing('suppressions', [
            'business_id' => $business->id, 'contact_identifier' => '+15551234567', 'reason' => 'unsubscribed',
        ]);
    }

    public function test_email_audience_preview_reports_consent_suppression_and_automated_exclusions(): void
    {
        $business = BusinessProfile::factory()->create();
        ContactConsent::create([
            'business_id' => $business->id, 'channel' => 'email', 'contact_identifier' => 'subscribed@example.test',
            'scope' => 'marketing', 'status' => 'opted_in', 'source' => 'form', 'captured_at' => now(),
        ]);
        ContactConsent::create([
            'business_id' => $business->id, 'channel' => 'email', 'contact_identifier' => 'complaint@example.test',
            'scope' => 'marketing', 'status' => 'opted_in', 'source' => 'form', 'captured_at' => now(),
        ]);
        Suppression::create([
            'business_id' => $business->id, 'channel' => 'email', 'contact_identifier' => 'complaint@example.test',
            'reason' => 'complained', 'source' => 'admin', 'suppressed_at' => now(),
        ]);

        $preview = app(EmailCampaignAudienceService::class)->previewStats($business, [
            'mode' => 'manual',
            'recipients' => [
                'subscribed@example.test', 'unknown@example.test', 'complaint@example.test', 'noreply@example.test',
            ],
        ]);

        $this->assertSame(4, $preview['total_matched']);
        $this->assertSame(1, $preview['count']);
        $this->assertSame(1, $preview['excluded_no_consent']);
        $this->assertSame(1, $preview['excluded_suppression']);
        $this->assertSame(1, $preview['excluded_automated_sender']);
    }
}
