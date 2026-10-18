<?php

namespace Tests\Feature;

use App\Models\BusinessProfile;
use App\Models\Channel;
use App\Models\ContactConsent;
use App\Models\Conversation;
use App\Models\User;
use App\Services\ConsentGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class GrantMarketingConsentTest extends TestCase
{
    use RefreshDatabase;

    public function test_grant_consent_command_opts_in_conversation(): void
    {
        $owner = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $owner->id]);
        $channel = Channel::create([
            'user_id' => $owner->id,
            'business_id' => $business->id,
            'type' => 'whatsapp',
            'status' => 'connected',
            'access_token' => 'test-token',
        ]);
        $conversation = Conversation::create([
            'channel_id' => $channel->id,
            'business_id' => $business->id,
            'sender_id' => '+966501234567',
        ]);

        $gate = app(ConsentGate::class);
        $this->assertFalse($gate->checkConversation($conversation, 'marketing')->allowed);

        // Run artisan command
        $exitCode = Artisan::call('consent:grant', ['conversationId' => $conversation->id]);
        $this->assertEquals(0, $exitCode);

        // Should now be allowed!
        $this->assertTrue($gate->checkConversation($conversation, 'marketing')->allowed);
        $this->assertDatabaseHas('contact_consents', [
            'business_id' => $business->id,
            'channel' => 'whatsapp',
            'contact_identifier' => '+966501234567',
            'scope' => 'marketing',
            'status' => 'opted_in',
        ]);
    }
}
