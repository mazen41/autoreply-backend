<?php

namespace Tests\Feature;

use App\Models\BusinessProfile;
use App\Models\ContactConsent;
use App\Models\EmailCampaign;
use App\Models\EmailCampaignRecipient;
use App\Models\Suppression;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmailUnsubscribeTest extends TestCase
{
    use RefreshDatabase;

    public function test_signed_tracking_token_unsubscribes_and_suppresses_email_contact(): void
    {
        $owner = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $owner->id]);
        $campaign = EmailCampaign::create([
            'business_id' => $business->id, 'name' => 'News', 'subject' => 'News',
            'content' => '<p>News</p>', 'status' => 'sent',
        ]);
        $recipient = EmailCampaignRecipient::create([
            'email_campaign_id' => $campaign->id, 'email' => 'reader@example.test',
            'tracking_token' => str_repeat('e', 48), 'status' => 'sent',
        ]);
        ContactConsent::create([
            'business_id' => $business->id, 'channel' => 'email', 'contact_identifier' => 'reader@example.test',
            'scope' => 'marketing', 'status' => 'opted_in', 'source' => 'form', 'captured_at' => now(),
        ]);

        $this->getJson('/api/email-campaigns/unsubscribe/' . $recipient->tracking_token)->assertOk();

        $this->assertSame('opted_out', ContactConsent::firstOrFail()->status);
        $this->assertDatabaseHas('suppressions', [
            'business_id' => $business->id, 'channel' => 'email', 'contact_identifier' => 'reader@example.test',
            'reason' => 'unsubscribed',
        ]);
        $this->getJson('/api/email-campaigns/unsubscribe/12')->assertNotFound();
    }
}
