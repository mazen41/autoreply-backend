<?php

namespace Tests\Feature;

use App\Mail\EmailCampaignMail;
use App\Jobs\CheckNoReplyForMessage;
use App\Models\BusinessProfile;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\ContactConsent;
use App\Models\EmailCampaign;
use App\Models\EmailCampaignRecipient;
use App\Models\Message;
use App\Models\Sequence;
use App\Models\SequenceEnrollment;
use App\Models\User;
use App\Services\SequenceTriggerService;
use App\Services\SequenceEnrollmentService;
use App\Services\OAuthStateService;
use App\Support\SensitiveLogContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuditSecurityAndSequenceTriggersTest extends TestCase
{
    use RefreshDatabase;

    public function test_facebook_connect_requires_authenticated_post_and_does_not_put_session_token_in_a_url(): void
    {
        $user = User::factory()->create();
        $business = BusinessProfile::create(['user_id' => $user->id, 'name' => 'OAuth test business']);
        Sanctum::actingAs($user);

        $response = $this->withHeader('X-Business-Id', (string) $business->id)->postJson('/api/channels/connect/facebook')->assertOk();
        $oauthUrl = $response->json('url');

        $this->assertIsString($oauthUrl);
        $this->assertStringStartsWith('https://www.facebook.com/', $oauthUrl);
        $this->assertStringNotContainsString('token=', $oauthUrl);
        $this->assertStringNotContainsString('test-session-secret', $oauthUrl);
        $this->get('/api/channels/connect/facebook?token=test-session-secret')->assertStatus(405);

        config(['services.salla.client_id' => 'test-salla-app', 'services.salla.client_secret' => 'test-only-secret']);
        $sallaUrl = $this->postJson('/api/channels/connect/salla', [], ['X-Business-Id' => (string) $business->id])->assertOk()->json('url');
        $this->assertStringStartsWith('https://accounts.salla.sa/', $sallaUrl);
        $this->assertStringNotContainsString('token=', $sallaUrl);
        $this->get('/api/channels/connect/salla?token=test-session-secret')->assertStatus(405);

        $this->postJson('/api/channels/connect/tiktok', [], ['X-Business-Id' => (string) $business->id])
            ->assertStatus(410)
            ->assertJsonPath('message', 'TikTok messaging is disabled because this integration has no supported customer messaging API.');
        $this->get('/api/channels/connect/tiktok?token=test-session-secret')->assertStatus(405);
    }

    public function test_oauth_state_is_provider_bound_short_lived_and_single_use(): void
    {
        $service = app(OAuthStateService::class);
        $state = $service->issue('facebook', 41, ['redirect' => 'dashboard']);

        $this->assertStringStartsWith('naz_', $state);
        $this->assertNull($service->consume('gmail', $state));
        $this->assertSame(['user_id' => 41, 'context' => ['redirect' => 'dashboard']], $service->consume('facebook', $state));
        $this->assertNull($service->consume('facebook', $state));

        $expired = $service->issue('shopify', 42, ['shop' => 'test.myshopify.com']);
        $this->travel(6)->minutes();
        $this->assertNull($service->consume('shopify', $expired));
        $this->travelBack();
    }

    public function test_oauth_callbacks_reject_forged_user_id_state_before_token_exchange(): void
    {
        Http::fake();

        $this->get('/api/channels/callback/facebook?code=untrusted-code&state=41%3Adashboard')
            ->assertRedirectContains('facebook_invalid_state');
        $this->get('/api/channels/callback/tiktok?code=untrusted-code&state=41%3Adashboard')
            ->assertRedirectContains('tiktok_messaging_unavailable');
        $this->get('/api/channels/callback/gmail?code=untrusted-code&state=41')
            ->assertRedirectContains('gmail_invalid_state');
        $this->get('/api/channels/callback/salla?code=untrusted-code&state=41%3Adashboard')
            ->assertRedirectContains('salla_invalid_state');
        $this->postJson('/api/channels/woocommerce/callback', [
            'user_id' => '41',
            'consumer_key' => 'untrusted-key',
            'consumer_secret' => 'untrusted-secret',
            'key_permissions' => 'read_write',
        ])->assertUnauthorized();

        Http::assertNothingSent();
    }

    public function test_facebook_oauth_callback_does_not_log_authorization_code_or_state(): void
    {
        $assertSensitiveValuesRedacted = function (...$args): bool {
            $serialized = json_encode($args, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $this->assertStringNotContainsString('oauth-sensitive-code', $serialized);
            $this->assertStringNotContainsString('oauth-sensitive-state', $serialized);
            return true;
        };
        Log::shouldReceive('info')->zeroOrMoreTimes()->withArgs($assertSensitiveValuesRedacted);
        Log::shouldReceive('error')->zeroOrMoreTimes()->withArgs($assertSensitiveValuesRedacted);
        Log::shouldReceive('warning')->zeroOrMoreTimes()->withArgs($assertSensitiveValuesRedacted);

        $this->get('/api/channels/callback/facebook?code=oauth-sensitive-code&state=oauth-sensitive-state')
            ->assertRedirectContains('facebook_invalid_state');
    }

    public function test_paymob_redirect_callback_does_not_log_query_credentials_or_payment_identifiers(): void
    {
        $assertSensitiveValuesOmitted = function (...$args): bool {
            $serialized = json_encode($args, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $this->assertStringNotContainsString('payment-sensitive-hmac', $serialized);
            $this->assertStringNotContainsString('payment-sensitive-transaction', $serialized);
            return true;
        };

        Log::shouldReceive('info')->once()->withArgs($assertSensitiveValuesOmitted);
        Log::shouldReceive('error')->once()->withArgs($assertSensitiveValuesOmitted);

        $this->get('/api/payments/callback?id=payment-sensitive-transaction&hmac=payment-sensitive-hmac&success=true')
            ->assertRedirectContains('payment=failed');
    }

    public function test_sensitive_log_context_redacts_nested_oauth_credentials(): void
    {
        $redacted = SensitiveLogContext::redact([
            'access_token' => 'access-secret',
            'details' => [
                'refreshToken' => 'refresh-secret',
                'authorization' => 'Bearer auth-secret',
                'code' => 'oauth-code',
            ],
            'diagnostic' => 'safe diagnostic',
        ]);

        $serialized = json_encode($redacted, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        foreach (['access-secret', 'refresh-secret', 'auth-secret', 'oauth-code'] as $secret) {
            $this->assertStringNotContainsString($secret, $serialized);
        }
        $this->assertStringContainsString('safe diagnostic', $serialized);
    }

    public function test_meta_webhook_fails_closed_without_app_secret(): void
    {
        config(['services.meta.app_secret' => null]);

        $this->postJson('/api/webhook/meta', [
            'object' => 'page',
            'entry' => [['id' => 'page-1', 'messaging' => []]],
        ])->assertStatus(503);
    }

    public function test_campaign_click_only_redirects_to_a_stored_signed_link(): void
    {
        $recipient = $this->makeEmailRecipient();
        $destination = 'https://store.example/products?ref=campaign';
        $linkToken = hash_hmac(
            'sha256',
            $recipient->tracking_token . "\n" . $destination,
            (string) config('app.key')
        );
        $recipient->update(['tracking_links' => [$linkToken => $destination]]);

        $this->get('/api/email-campaigns/track/click/' . $recipient->tracking_token . '/' . $linkToken)
            ->assertRedirect($destination);
        $this->assertNotNull($recipient->fresh()->clicked_at);

        $this->get('/api/email-campaigns/track/click/' . $recipient->tracking_token . '?url=https://evil.example')
            ->assertNotFound();
    }

    public function test_numeric_recipient_ids_are_not_accepted_for_open_tracking(): void
    {
        $recipient = $this->makeEmailRecipient();

        $this->get('/api/email-campaigns/track/open/' . $recipient->id)->assertOk();

        $this->assertNull($recipient->fresh()->opened_at);
    }

    public function test_email_links_are_saved_and_rewritten_to_signed_tracking_paths(): void
    {
        $recipient = $this->makeEmailRecipient();
        $mail = new EmailCampaignMail($recipient->campaign, $recipient);

        $built = $mail->build();
        $html = $built->render();
        $recipient->refresh();

        $this->assertStringContainsString('/api/email-campaigns/track/click/' . $recipient->tracking_token . '/', $html);
        $this->assertStringNotContainsString('?url=', $html);
        $this->assertCount(1, $recipient->tracking_links);
        $this->assertSame('https://store.example/products', array_values($recipient->tracking_links)[0]);
    }

    public function test_manual_sequences_do_not_auto_enroll_and_new_user_is_first_message_only(): void
    {
        Bus::fake();
        $business = BusinessProfile::factory()->create();
        $channel = Channel::factory()->create(['business_id' => $business->id, 'type' => 'whatsapp']);
        $conversation = Conversation::factory()->create([
            'business_id' => $business->id,
            'channel_id' => $channel->id,
        ]);
        ContactConsent::create([
            'business_id' => $business->id,
            'channel' => $channel->type === 'gmail' ? 'email' : $channel->type,
            'contact_identifier' => app(\App\Services\ConsentGate::class)->normalizeIdentifier(
                $channel->type === 'gmail' ? ($conversation->sender_email ?? '') : $conversation->sender_id,
                $channel->type === 'gmail' ? 'email' : $channel->type
            ),
            'scope' => 'marketing',
            'status' => 'opted_in',
            'source' => 'form',
            'captured_at' => now(),
        ]);
        $manual = Sequence::factory()->create([
            'business_id' => $business->id,
            'trigger_type' => 'manual',
            'status' => 'active',
        ]);
        $newUser = Sequence::factory()->create([
            'business_id' => $business->id,
            'trigger_type' => 'new_user',
            'status' => 'active',
        ]);
        Message::factory()->create(['conversation_id' => $conversation->id, 'direction' => 'inbound']);

        $service = app(SequenceTriggerService::class);
        $service->checkAndEnrollForMessageReceived($conversation);

        $this->assertDatabaseHas('sequence_enrollments', [
            'sequence_id' => $newUser->id,
            'conversation_id' => $conversation->id,
        ]);
        $this->assertDatabaseMissing('sequence_enrollments', [
            'sequence_id' => $manual->id,
            'conversation_id' => $conversation->id,
        ]);

        Message::factory()->create(['conversation_id' => $conversation->id, 'direction' => 'inbound']);
        $secondConversation = Conversation::factory()->create([
            'business_id' => $business->id,
            'channel_id' => $channel->id,
        ]);
        Message::factory()->create(['conversation_id' => $secondConversation->id, 'direction' => 'inbound']);
        Message::factory()->create(['conversation_id' => $secondConversation->id, 'direction' => 'inbound']);

        $service->checkAndEnrollForMessageReceived($conversation);
        $service->checkAndEnrollForMessageReceived($secondConversation);

        $this->assertSame(1, SequenceEnrollment::where('sequence_id', $newUser->id)->count());
        $this->assertDatabaseMissing('sequence_enrollments', [
            'sequence_id' => $newUser->id,
            'conversation_id' => $secondConversation->id,
        ]);
    }

    public function test_no_reply_cycles_are_persisted_and_duplicate_or_stale_jobs_do_not_enroll(): void
    {
        Bus::fake();
        $business = BusinessProfile::factory()->create();
        $channel = Channel::factory()->create(['business_id' => $business->id, 'type' => 'whatsapp']);
        $conversation = Conversation::factory()->create([
            'business_id' => $business->id,
            'channel_id' => $channel->id,
        ]);
        ContactConsent::create([
            'business_id' => $business->id,
            'channel' => $channel->type,
            'contact_identifier' => app(\App\Services\ConsentGate::class)->normalizeIdentifier($conversation->sender_id, $channel->type),
            'scope' => 'marketing',
            'status' => 'opted_in',
            'source' => 'form',
            'captured_at' => now(),
        ]);
        $sequence = Sequence::factory()->create([
            'business_id' => $business->id,
            'trigger_type' => 'no_reply',
            'trigger_config' => ['delay_value' => 1, 'delay_unit' => 'minutes'],
            'settings' => ['allow_reentry' => true],
            'status' => 'active',
        ]);
        $replyOne = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'direction' => 'outbound',
            'is_ai' => true,
        ]);
        $replyTwo = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'direction' => 'outbound',
            'is_ai' => true,
        ]);

        $triggerService = app(SequenceTriggerService::class);
        $triggerService->scheduleNoReplyTimers($conversation, $replyOne);
        $triggerService->scheduleNoReplyTimers($conversation, $replyTwo);

        $this->assertDatabaseHas('sequence_no_reply_cycles', [
            'sequence_id' => $sequence->id,
            'conversation_id' => $conversation->id,
            'cycle' => 2,
            'ai_reply_message_id' => $replyTwo->id,
        ]);

        $enrollmentService = app(SequenceEnrollmentService::class);
        (new CheckNoReplyForMessage($sequence->id, $conversation->id, $replyOne->id, 1))
            ->handle($enrollmentService);
        $this->assertSame(0, SequenceEnrollment::where('sequence_id', $sequence->id)->count());

        $currentTimer = new CheckNoReplyForMessage($sequence->id, $conversation->id, $replyTwo->id, 2);
        $currentTimer->handle($enrollmentService);
        $currentTimer->handle($enrollmentService);

        $this->assertSame(1, SequenceEnrollment::where('sequence_id', $sequence->id)->count());
        $this->assertNotNull(DB::table('sequence_no_reply_cycles')
            ->where('sequence_id', $sequence->id)
            ->where('conversation_id', $conversation->id)
            ->value('processed_at'));
    }

    public function test_no_reply_timer_enrolls_contact_without_explicit_marketing_consent_when_not_opted_out(): void
    {
        $business = BusinessProfile::factory()->create();
        $channel = Channel::factory()->create(['business_id' => $business->id, 'type' => 'whatsapp']);
        $conversation = Conversation::factory()->create([
            'business_id' => $business->id,
            'channel_id' => $channel->id,
        ]);
        $sequence = Sequence::factory()->create([
            'business_id' => $business->id,
            'trigger_type' => 'no_reply',
            'status' => 'active',
        ]);
        $reply = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'direction' => 'outbound',
            'is_ai' => true,
        ]);
        DB::table('sequence_no_reply_cycles')->insert([
            'sequence_id' => $sequence->id,
            'conversation_id' => $conversation->id,
            'cycle' => 1,
            'ai_reply_message_id' => $reply->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        (new CheckNoReplyForMessage($sequence->id, $conversation->id, $reply->id, 1))
            ->handle(app(SequenceEnrollmentService::class));

        $this->assertSame(1, SequenceEnrollment::where('sequence_id', $sequence->id)->count());
        $this->assertNotNull(DB::table('sequence_no_reply_cycles')
            ->where('sequence_id', $sequence->id)
            ->where('conversation_id', $conversation->id)
            ->value('processed_at'));
    }

    public function test_no_reply_timer_does_not_reenter_prior_contact_without_explicit_setting(): void
    {
        $business = BusinessProfile::factory()->create();
        $channel = Channel::factory()->create(['business_id' => $business->id, 'type' => 'whatsapp']);
        $conversation = Conversation::factory()->create([
            'business_id' => $business->id,
            'channel_id' => $channel->id,
        ]);
        ContactConsent::create([
            'business_id' => $business->id,
            'channel' => 'whatsapp',
            'contact_identifier' => app(\App\Services\ConsentGate::class)->normalizeIdentifier($conversation->sender_id, 'whatsapp'),
            'scope' => 'marketing',
            'status' => 'opted_in',
            'source' => 'form',
            'captured_at' => now(),
        ]);
        $sequence = Sequence::factory()->create([
            'business_id' => $business->id,
            'trigger_type' => 'no_reply',
            'status' => 'active',
            'settings' => [],
        ]);
        SequenceEnrollment::factory()->create([
            'sequence_id' => $sequence->id,
            'conversation_id' => $conversation->id,
            'status' => 'completed',
        ]);
        $reply = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'direction' => 'outbound',
            'is_ai' => true,
        ]);
        DB::table('sequence_no_reply_cycles')->insert([
            'sequence_id' => $sequence->id,
            'conversation_id' => $conversation->id,
            'cycle' => 1,
            'ai_reply_message_id' => $reply->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        (new CheckNoReplyForMessage($sequence->id, $conversation->id, $reply->id, 1))
            ->handle(app(SequenceEnrollmentService::class));

        $this->assertSame(1, SequenceEnrollment::where('sequence_id', $sequence->id)->count());
        $this->assertNotNull(DB::table('sequence_no_reply_cycles')
            ->where('sequence_id', $sequence->id)
            ->where('conversation_id', $conversation->id)
            ->value('processed_at'));
    }

    private function makeEmailRecipient(): EmailCampaignRecipient
    {
        $business = BusinessProfile::factory()->create();
        $campaign = EmailCampaign::create([
            'business_id' => $business->id,
            'name' => 'Test campaign',
            'subject' => 'Test',
            'content' => '<a href="https://store.example/products">Products</a>',
            'status' => 'sending',
        ]);

        return EmailCampaignRecipient::create([
            'email_campaign_id' => $campaign->id,
            'tracking_token' => str_repeat('a', 48),
            'email' => 'customer@example.com',
            'status' => 'sent',
        ]);
    }
}
