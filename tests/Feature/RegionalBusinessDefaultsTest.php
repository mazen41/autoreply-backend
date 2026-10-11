<?php

namespace Tests\Feature;

use App\Models\BusinessProfile;
use App\Models\Channel;
use App\Models\ContactConsent;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\InboundConsentKeywordHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegionalBusinessDefaultsTest extends TestCase
{
    use RefreshDatabase;

    public function test_egyptian_onboarding_saves_egyptian_currency_and_cairo_timezone(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/onboarding/step2', [
            'business_name' => 'Cairo Store',
            'country' => 'Egypt',
        ]);

        $response->assertOk();
        $business = BusinessProfile::where('user_id', $user->id)->firstOrFail();
        $this->assertSame('EGP', $business->currency);
        $this->assertSame('Africa/Cairo', $business->timezone);
    }

    public function test_saudi_onboarding_saves_sar_and_riyadh_timezone(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/onboarding/step2', [
            'business_name' => 'Riyadh Store',
            'country' => 'Saudi Arabia',
        ])->assertOk();

        $business = BusinessProfile::where('user_id', $user->id)->firstOrFail();
        $this->assertSame('SAR', $business->currency);
        $this->assertSame('Asia/Riyadh', $business->timezone);
    }

    public function test_arabic_stop_keyword_records_marketing_opt_out(): void
    {
        $user = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $user->id]);
        $channel = Channel::create([
            'user_id' => $user->id, 'business_id' => $business->id, 'type' => 'whatsapp',
            'page_id' => 'regional-wa', 'access_token' => '', 'status' => 'connected',
        ]);
        $conversation = Conversation::create([
            'business_id' => $business->id, 'channel_id' => $channel->id, 'sender_id' => '+201000000001',
        ]);
        $message = Message::create([
            'conversation_id' => $conversation->id, 'content' => 'إلغاء الاشتراك', 'direction' => 'inbound',
        ]);

        app(InboundConsentKeywordHandler::class)->handle($message);

        $this->assertDatabaseHas('contact_consents', [
            'business_id' => $business->id, 'channel' => 'whatsapp', 'scope' => 'marketing',
            'status' => 'opted_out',
        ]);
        $this->assertDatabaseHas('suppressions', [
            'business_id' => $business->id, 'channel' => 'whatsapp', 'reason' => 'unsubscribed',
        ]);
    }
}
