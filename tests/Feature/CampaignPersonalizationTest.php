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
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CampaignPersonalizationTest extends TestCase
{
    use RefreshDatabase;

    private function buildScenario(string $senderName, string $messageTemplate): array
    {
        $owner = User::factory()->create();
        $business = BusinessProfile::factory()->create([
            'user_id' => $owner->id,
            'business_name' => 'NazBiz',
        ]);
        $channel = Channel::create([
            'user_id' => $owner->id,
            'business_id' => $business->id,
            'type' => 'instagram',
            'page_id' => 'personalization-page',
            'access_token' => 'test-token',
            'status' => 'connected',
        ]);

        $conversation = Conversation::create([
            'channel_id' => $channel->id,
            'business_id' => $business->id,
            'sender_id' => 'personalization-contact',
            'sender_name' => $senderName,
            'last_message_at' => now(),
        ]);
        Message::create([
            'conversation_id' => $conversation->id,
            'content' => 'Hi',
            'direction' => 'inbound',
            'created_at' => now(),
        ]);
        ContactConsent::create([
            'business_id' => $business->id,
            'channel' => 'instagram',
            'contact_identifier' => 'personalization-contact',
            'scope' => 'marketing',
            'status' => 'opted_in',
            'source' => 'form',
            'captured_at' => now(),
        ]);

        $campaign = Campaign::create([
            'business_id' => $business->id,
            'channel_id' => $channel->id,
            'name' => 'Personalization Test',
            'message' => $messageTemplate,
            'status' => 'sending',
            'total_recipients' => 1,
        ]);
        $log = CampaignLog::create([
            'campaign_id' => $campaign->id,
            'conversation_id' => $conversation->id,
            'status' => 'queued',
        ]);

        return compact('campaign', 'log', 'conversation', 'channel');
    }

    public function test_double_curly_name_is_replaced(): void
    {
        Http::fake(['*' => Http::response(['message_id' => 'msg-1'], 200)]);
        $s = $this->buildScenario('Mazen Hossny', 'Hello {{name}}, welcome!');

        (new SendCampaignMessage($s['log']->id))->handle();

        $sent = Message::where('conversation_id', $s['conversation']->id)
            ->where('direction', 'outbound')->latest()->first();
        $this->assertNotNull($sent);
        $this->assertStringContainsString('Mazen Hossny', $sent->content);
        $this->assertStringNotContainsString('{{name}}', $sent->content);
    }

    public function test_double_curly_customer_name_is_replaced(): void
    {
        Http::fake(['*' => Http::response(['message_id' => 'msg-2'], 200)]);
        $s = $this->buildScenario('Sara', 'Hi {{customer_name}}, how are you?');

        (new SendCampaignMessage($s['log']->id))->handle();

        $sent = Message::where('conversation_id', $s['conversation']->id)
            ->where('direction', 'outbound')->latest()->first();
        $this->assertNotNull($sent);
        $this->assertStringContainsString('Sara', $sent->content);
        $this->assertStringNotContainsString('{{customer_name}}', $sent->content);
    }

    public function test_business_name_variable_is_replaced(): void
    {
        Http::fake(['*' => Http::response(['message_id' => 'msg-3'], 200)]);
        $s = $this->buildScenario('Ali', 'Welcome to {{business_name}}!');

        (new SendCampaignMessage($s['log']->id))->handle();

        $sent = Message::where('conversation_id', $s['conversation']->id)
            ->where('direction', 'outbound')->latest()->first();
        $this->assertNotNull($sent);
        $this->assertStringContainsString('NazBiz', $sent->content);
        $this->assertStringNotContainsString('{{business_name}}', $sent->content);
    }

    public function test_legacy_single_curly_name_still_works(): void
    {
        Http::fake(['*' => Http::response(['message_id' => 'msg-4'], 200)]);
        $s = $this->buildScenario('Omar', 'Hello {name}!');

        (new SendCampaignMessage($s['log']->id))->handle();

        $sent = Message::where('conversation_id', $s['conversation']->id)
            ->where('direction', 'outbound')->latest()->first();
        $this->assertNotNull($sent);
        $this->assertStringContainsString('Omar', $sent->content);
        $this->assertStringNotContainsString('{name}', $sent->content);
    }

    public function test_unknown_name_falls_back_to_there(): void
    {
        Http::fake(['*' => Http::response(['message_id' => 'msg-5'], 200)]);
        $s = $this->buildScenario('', 'Hi {{name}}!');

        (new SendCampaignMessage($s['log']->id))->handle();

        $sent = Message::where('conversation_id', $s['conversation']->id)
            ->where('direction', 'outbound')->latest()->first();
        $this->assertNotNull($sent);
        $this->assertEquals('Hi there!', $sent->content);
    }

    public function test_phone_number_name_falls_back_to_there(): void
    {
        Http::fake(['*' => Http::response(['message_id' => 'msg-6'], 200)]);
        $s = $this->buildScenario('+966500000001', 'test {{customer_name}}');

        (new SendCampaignMessage($s['log']->id))->handle();

        $sent = Message::where('conversation_id', $s['conversation']->id)
            ->where('direction', 'outbound')->latest()->first();
        $this->assertNotNull($sent);
        $this->assertEquals('test there', $sent->content);
    }
}
