<?php

namespace Tests\Feature;

use App\Models\BusinessProfile;
use App\Models\Channel;
use App\Models\Campaign;
use App\Models\CampaignLog;
use App\Models\ContactConsent;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Models\WhatsAppCloudTemplate;
use App\Jobs\SendCampaignMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsAppCampaignPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_unofficial_evolution_channel_cannot_create_a_bulk_campaign(): void
    {
        $owner = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $owner->id]);
        $channel = Channel::create([
            'user_id' => $owner->id,
            'business_id' => $business->id,
            'type' => 'whatsapp',
            'page_id' => 'evolution-instance',
            'access_token' => '',
            'status' => 'connected',
            'metadata' => ['whatsapp_provider' => 'evolution', 'unofficial_risk_accepted' => true],
        ]);

        $response = $this->actingAs($owner)->withHeader('X-Business-Id', (string) $business->id)
            ->postJson("/api/businesses/{$business->id}/campaigns", [
                'channel_id' => $channel->id,
                'name' => 'Offer',
                'message' => 'Buy now',
            ]);

        $response->assertUnprocessable()->assertJsonPath('error', 'WhatsApp campaigns are disabled for unofficial Evolution connections.');
        $this->assertDatabaseCount('campaigns', 0);
    }

    public function test_cloud_campaign_requires_an_approved_template_belonging_to_same_channel(): void
    {
        $owner = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $owner->id]);
        $channel = Channel::create([
            'user_id' => $owner->id,
            'business_id' => $business->id,
            'type' => 'whatsapp',
            'page_id' => 'cloud-phone-id',
            'access_token' => 'cloud-token',
            'status' => 'connected',
            'metadata' => ['whatsapp_provider' => 'cloud', 'whatsapp_business_account_id' => 'waba-id'],
        ]);
        $template = WhatsAppCloudTemplate::create([
            'business_id' => $business->id,
            'channel_id' => $channel->id,
            'name' => 'sale_offer',
            'language' => 'en_US',
            'category' => 'MARKETING',
            'components' => [['type' => 'BODY', 'text' => 'Sale']],
            'status' => 'APPROVED',
        ]);

        $response = $this->actingAs($owner)->withHeader('X-Business-Id', (string) $business->id)
            ->postJson("/api/businesses/{$business->id}/campaigns", [
                'channel_id' => $channel->id,
                'name' => 'Offer',
                'message' => 'Internal template campaign record',
                'whatsapp_cloud_template_id' => $template->id,
                'whatsapp_template_components' => [],
            ]);

        $response->assertOk()->assertJsonPath('campaign.whatsapp_cloud_template_id', $template->id);
    }

    public function test_campaign_job_sends_cloud_template_and_saves_meta_message_id(): void
    {
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.campaign-1']]], 200)]);
        $owner = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $owner->id]);
        $channel = Channel::create([
            'user_id' => $owner->id, 'business_id' => $business->id, 'type' => 'whatsapp',
            'page_id' => 'cloud-phone-id', 'access_token' => 'cloud-token', 'status' => 'connected',
            'metadata' => ['whatsapp_provider' => 'cloud', 'whatsapp_business_account_id' => 'waba-id'],
        ]);
        $template = WhatsAppCloudTemplate::create([
            'business_id' => $business->id, 'channel_id' => $channel->id, 'name' => 'sale_offer',
            'language' => 'en_US', 'category' => 'MARKETING', 'components' => [['type' => 'BODY']], 'status' => 'APPROVED',
        ]);
        $conversation = Conversation::create([
            'business_id' => $business->id, 'channel_id' => $channel->id, 'sender_id' => '201155555555',
        ]);
        ContactConsent::create([
            'business_id' => $business->id, 'channel' => 'whatsapp', 'contact_identifier' => $conversation->sender_id,
            'scope' => 'marketing', 'status' => 'opted_in', 'source' => 'form', 'captured_at' => now(),
        ]);
        $campaign = Campaign::create([
            'business_id' => $business->id, 'channel_id' => $channel->id, 'whatsapp_cloud_template_id' => $template->id,
            'name' => 'Sale', 'message' => 'Internal record', 'status' => 'sending', 'total_recipients' => 1,
        ]);
        $log = CampaignLog::create(['campaign_id' => $campaign->id, 'conversation_id' => $conversation->id, 'status' => 'queued']);

        (new SendCampaignMessage($log->id))->handle();

        $this->assertSame('sent', $log->fresh()->status);
        $this->assertSame('wamid.campaign-1', Message::where('conversation_id', $conversation->id)->where('direction', 'outbound')->value('whatsapp_message_id'));
        Http::assertSent(fn ($request) => $request['type'] === 'template'
            && $request['template']['name'] === 'sale_offer'
            && $request['to'] === $conversation->sender_id);
    }
}
