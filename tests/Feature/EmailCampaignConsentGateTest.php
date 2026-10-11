<?php

namespace Tests\Feature;

use App\Jobs\SendEmailCampaignMessage;
use App\Models\BusinessProfile;
use App\Models\ContactConsent;
use App\Models\EmailCampaign;
use App\Models\EmailCampaignRecipient;
use App\Models\Suppression;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Http\Client\ConnectionException;
use App\Services\EmailCampaignAudienceService;
use Tests\TestCase;

class EmailCampaignConsentGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_email_campaign_does_not_send_without_marketing_consent(): void
    {
        Mail::fake();
        $owner = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $owner->id]);
        $campaign = EmailCampaign::create([
            'business_id' => $business->id, 'name' => 'Campaign', 'subject' => 'Offer',
            'content' => '<p>Buy</p>', 'status' => 'sending', 'total_recipients' => 1,
        ]);
        $recipient = EmailCampaignRecipient::create([
            'email_campaign_id' => $campaign->id, 'email' => 'no-consent@example.test', 'status' => 'pending',
        ]);

        (new SendEmailCampaignMessage($recipient->id))->handle();

        $this->assertSame('failed', $recipient->fresh()->status);
        $this->assertStringContainsString('no_consent', $recipient->fresh()->error_message);
        $this->assertSame(1, $campaign->fresh()->failed_count);
        Mail::assertNothingSent();
    }

    public function test_transient_email_transport_failure_releases_recipient_for_retry(): void
    {
        [$campaign, $recipient] = $this->makeConsentedRecipient('retry@example.test');
        Mail::shouldReceive('to')->once()->with('retry@example.test')->andThrow(new ConnectionException('Connection timed out'));

        $job = (new SendEmailCampaignMessage($recipient->id))->withFakeQueueInteractions();
        $job->handle();

        $this->assertSame('pending', $recipient->fresh()->status);
        $this->assertSame(0, $campaign->fresh()->failed_count);
        $job->assertReleased(60);
    }

    public function test_permanent_email_recipient_failure_is_failed_without_release(): void
    {
        [$campaign, $recipient] = $this->makeConsentedRecipient('invalid@example.test');
        $mailer = \Mockery::mock();
        $mailer->shouldReceive('send')->once()->andThrow(new \RuntimeException('Invalid recipient address'));
        Mail::shouldReceive('to')->once()->with('invalid@example.test')->andReturn($mailer);

        $job = (new SendEmailCampaignMessage($recipient->id))->withFakeQueueInteractions();
        $job->handle();

        $this->assertSame('failed', $recipient->fresh()->status);
        $this->assertSame(1, $campaign->fresh()->failed_count);
        $job->assertNotReleased();
    }

    private function makeConsentedRecipient(string $email): array
    {
        $owner = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $owner->id]);
        $campaign = EmailCampaign::create([
            'business_id' => $business->id,
            'name' => 'Campaign',
            'subject' => 'Offer',
            'content' => '<p>Buy</p>',
            'status' => 'sending',
            'total_recipients' => 1,
        ]);
        $recipient = EmailCampaignRecipient::create([
            'email_campaign_id' => $campaign->id,
            'email' => $email,
            'status' => 'pending',
        ]);
        ContactConsent::create([
            'business_id' => $business->id,
            'channel' => 'email',
            'contact_identifier' => $email,
            'scope' => 'marketing',
            'status' => 'opted_in',
            'source' => 'test',
            'captured_at' => now(),
        ]);

        return [$campaign, $recipient];
    }

    public function test_email_campaign_accepts_contact_audience_selection(): void
    {
        $owner = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $owner->id]);

        $response = $this->actingAs($owner)
            ->withHeader('X-Business-Id', (string) $business->id)
            ->postJson('/api/email-campaigns', [
                'name' => 'Known contacts',
                'subject' => 'An update',
                'content' => 'Hello',
                'audience_criteria' => [
                    'mode' => 'contacts',
                    'channel_ids' => [],
                    'channel_types' => ['whatsapp'],
                    'last_active_days' => 30,
                ],
            ]);

        $response->assertCreated();
        $this->assertSame('contacts', $response->json('campaign.audience_criteria.mode'));
        $this->assertSame(['whatsapp'], $response->json('campaign.audience_criteria.channel_types'));
    }

    public function test_email_audience_preview_separates_block_reasons_and_reports_effective_sender(): void
    {
        $owner = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $owner->id]);
        config(['mail.from.name' => 'Naz Updates', 'mail.from.address' => 'updates@example.test']);

        ContactConsent::create([
            'business_id' => $business->id, 'channel' => 'email', 'contact_identifier' => 'eligible@example.test',
            'scope' => 'marketing', 'status' => 'opted_in', 'source' => 'form', 'captured_at' => now(),
        ]);
        ContactConsent::create([
            'business_id' => $business->id, 'channel' => 'email', 'contact_identifier' => 'opted-out@example.test',
            'scope' => 'marketing', 'status' => 'opted_out', 'source' => 'unsubscribe', 'captured_at' => now(),
        ]);
        Suppression::create([
            'business_id' => $business->id, 'channel' => 'email', 'contact_identifier' => 'suppressed@example.test',
            'reason' => 'unsubscribed', 'source' => 'unsubscribe', 'suppressed_at' => now(),
        ]);

        $preview = app(EmailCampaignAudienceService::class)->previewStats($business, [
            'mode' => 'manual',
            'recipients' => [
                'eligible@example.test', 'no-consent@example.test', 'opted-out@example.test',
                'suppressed@example.test', 'noreply@example.test',
            ],
        ]);

        $this->assertSame(1, $preview['count']);
        $this->assertSame(5, $preview['total_matched']);
        $this->assertSame(1, $preview['excluded_no_consent']);
        $this->assertSame(1, $preview['excluded_opted_out']);
        $this->assertSame(1, $preview['excluded_suppression']);
        $this->assertSame(1, $preview['excluded_automated_sender']);
        $this->assertSame('updates@example.test', $preview['sender']['address']);
        $this->assertTrue($preview['sender']['configured']);
    }
}
