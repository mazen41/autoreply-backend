<?php

namespace Tests\Feature;

use App\Jobs\DeliverWebhook;
use App\Jobs\SendEmailCampaignMessage;
use App\Mail\EmailCampaignMail;
use App\Models\BusinessProfile;
use App\Models\AiMetric;
use App\Models\AnalyticsDaily;
use App\Models\AutomationWorkflow;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\ContactConsent;
use App\Models\EmailCampaign;
use App\Models\EmailCampaignRecipient;
use App\Models\Message;
use App\Models\TeamMember;
use App\Models\User;
use App\Models\Webhook;
use App\Services\AnalyticsService;
use App\Services\AutomationEngine;
use App\Services\EmailCampaignAudienceService;
use App\Services\WebhookService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuditContinuationBackendTest extends TestCase
{
    use RefreshDatabase;

    public function test_outbound_webhooks_are_queued_signed_and_pinned_to_public_https(): void
    {
        Queue::fake();
        Http::fake(['https://1.1.1.1/*' => Http::response(['ok' => true], 200)]);
        $webhook = $this->makeWebhook('https://1.1.1.1/hooks/receiver');

        app(WebhookService::class)->triggerEvent($webhook->business_id, 'message.created', ['message_id' => 12]);
        Queue::assertPushed(DeliverWebhook::class, 1);

        app(WebhookService::class)->deliver($webhook, 'message.created', ['message_id' => 12]);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://1.1.1.1/hooks/receiver'
                && $request->hasHeader('X-Webhook-Signature')
                && hash_equals(
                    hash_hmac('sha256', $request->body(), 'test-webhook-secret'),
                    $request->header('X-Webhook-Signature')[0]
                );
        });
        $this->assertSame(1, $webhook->fresh()->success_count);
    }

    public function test_outbound_webhooks_reject_private_hosts_and_plain_http(): void
    {
        Http::fake();
        $webhook = $this->makeWebhook('https://127.0.0.1/internal');

        app(WebhookService::class)->deliver($webhook, 'message.created', ['message_id' => 12]);

        Http::assertNothingSent();
        $this->assertSame(1, $webhook->fresh()->failure_count);

        $webhook->update(['url' => 'http://1.1.1.1/internal']);
        app(WebhookService::class)->deliver($webhook, 'message.created', ['message_id' => 12]);

        Http::assertNothingSent();
        $this->assertSame(2, $webhook->fresh()->failure_count);

        app(WebhookService::class)->deliver($webhook, 'message.created', ['data' => str_repeat('x', 1_048_576)]);
        Http::assertNothingSent();
        $this->assertSame(3, $webhook->fresh()->failure_count);
    }

    public function test_webhook_api_hides_secrets_scopes_business_and_queues_test_delivery(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $user->id]);
        $webhook = Webhook::create([
            'business_id' => $business->id,
            'url' => 'https://1.1.1.1/receiver',
            'events' => ['message.created'],
            'secret' => 'private-signing-secret',
            'is_active' => false,
        ]);
        Sanctum::actingAs($user);

        $this->getJson("/api/businesses/{$business->id}/webhooks")
            ->assertOk()
            ->assertJsonMissing(['secret' => 'private-signing-secret']);
        $this->postJson("/api/businesses/{$business->id}/webhooks/{$webhook->id}/test")
            ->assertOk()
            ->assertJsonPath('success', true);
        Queue::assertPushed(DeliverWebhook::class, fn (DeliverWebhook $job) =>
            $job->webhookId === $webhook->id && $job->event === 'test' && $job->testOnly
        );

        $foreignBusiness = BusinessProfile::factory()->create();
        $this->getJson("/api/businesses/{$foreignBusiness->id}/webhooks")->assertForbidden();
    }

    public function test_webhook_api_generates_secret_and_requires_https_and_write_permission(): void
    {
        $owner = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $owner->id]);
        Sanctum::actingAs($owner);

        $this->postJson("/api/businesses/{$business->id}/webhooks", [
            'url' => 'https://127.0.0.1/internal',
            'events' => ['message.created'],
        ])->assertStatus(422);

        $this->postJson("/api/businesses/{$business->id}/webhooks", [
            'url' => 'http://hooks.example.test/receiver',
            'events' => ['message.created'],
        ])->assertStatus(422);

        $created = $this->postJson("/api/businesses/{$business->id}/webhooks", [
            'url' => 'https://1.1.1.1/receiver',
            'events' => ['message.created'],
        ])->assertCreated()->assertJsonPath('success', true);
        $initialSecret = $created->json('webhook.secret');
        $this->assertNotEmpty($initialSecret);
        $this->assertDatabaseHas('webhooks', [
            'business_id' => $business->id,
            'url' => 'https://1.1.1.1/receiver',
        ]);
        $savedWebhook = Webhook::where('business_id', $business->id)->firstOrFail();
        $this->assertSame($initialSecret, $savedWebhook->secret);
        $this->assertNotSame($initialSecret, $savedWebhook->getRawOriginal('secret'));

        $rotated = $this->postJson("/api/businesses/{$business->id}/webhooks/{$savedWebhook->id}/rotate-secret")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->json('secret');
        $this->assertNotEmpty($rotated);
        $this->assertNotSame($initialSecret, $rotated);
        $this->assertSame($rotated, $savedWebhook->fresh()->secret);
        $samplePayload = ['event' => 'message.created'];
        $oldSignature = hash_hmac(
            'sha256',
            json_encode($samplePayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            $initialSecret
        );
        $this->assertFalse(app(WebhookService::class)->verifySignature($samplePayload, $oldSignature, $rotated));
        $this->getJson("/api/businesses/{$business->id}/webhooks")
            ->assertOk()
            ->assertJsonMissing(['secret' => $rotated]);

        $this->putJson("/api/businesses/{$business->id}/webhooks/{$savedWebhook->id}", [
            'url' => 'https://169.254.169.254/latest/meta-data',
        ])->assertStatus(422);
        $savedWebhook->update(['url' => 'https://127.0.0.1/internal']);
        $this->postJson("/api/businesses/{$business->id}/webhooks/{$savedWebhook->id}/test")
            ->assertStatus(422);

        $viewer = User::factory()->create();
        TeamMember::factory()->create([
            'business_id' => $business->id,
            'user_id' => $viewer->id,
            'role' => 'viewer',
            'is_active' => true,
        ]);
        Sanctum::actingAs($viewer);
        $this->postJson("/api/businesses/{$business->id}/webhooks", [
            'url' => 'https://hooks.example.test/another',
            'events' => ['message.created'],
        ])->assertForbidden();
    }

    public function test_sequence_queue_metrics_and_recovery_are_admin_only(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        Sanctum::actingAs($user);

        $this->getJson('/api/sequences/queue/metrics')->assertForbidden();
        $this->postJson('/api/sequences/queue/reset-stuck')->assertForbidden();

        $admin = User::factory()->create(['is_admin' => true]);
        Sanctum::actingAs($admin);
        $this->getJson('/api/sequences/queue/metrics')->assertOk();
        $this->postJson('/api/sequences/queue/reset-stuck')->assertOk();
    }

    public function test_business_role_matrix_allows_manager_writes_but_limits_agents_to_conversations(): void
    {
        $owner = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $owner->id]);
        $manager = User::factory()->create();
        TeamMember::factory()->create([
            'business_id' => $business->id,
            'user_id' => $manager->id,
            'role' => 'manager',
            'is_active' => true,
        ]);

        Sanctum::actingAs($manager);
        $this->postJson("/api/businesses/{$business->id}/webhooks", [
            'url' => 'https://1.1.1.1/manager-hook',
            'events' => ['message.created'],
        ])->assertCreated();
        $this->getJson("/api/businesses/{$business->id}/team")->assertForbidden();
        $this->getJson("/api/businesses/{$business->id}/hours")->assertOk();
        $this->getJson("/api/businesses/{$business->id}/campaigns")->assertOk();

        $agent = User::factory()->create();
        TeamMember::factory()->create([
            'business_id' => $business->id,
            'user_id' => $agent->id,
            'role' => 'agent',
            'is_active' => true,
        ]);
        Sanctum::actingAs($agent);
        $this->getJson("/api/businesses/{$business->id}/analytics/dashboard")->assertForbidden();
        $this->getJson("/api/businesses/{$business->id}/campaigns")->assertForbidden();
        $this->postJson("/api/businesses/{$business->id}/webhooks", [
            'url' => 'https://1.1.1.1/agent-hook',
            'events' => ['message.created'],
        ])->assertForbidden();

        $viewer = User::factory()->create();
        TeamMember::factory()->create([
            'business_id' => $business->id,
            'user_id' => $viewer->id,
            'role' => 'viewer',
            'is_active' => true,
        ]);
        Sanctum::actingAs($viewer);
        $this->getJson("/api/businesses/{$business->id}/analytics/dashboard")->assertOk();
        $this->getJson("/api/businesses/{$business->id}/hours")->assertOk();
        $this->getJson("/api/businesses/{$business->id}/campaigns")->assertOk();
        $this->postJson("/api/businesses/{$business->id}/analytics/calculate")->assertForbidden();
    }

    public function test_workflow_webhook_blocks_private_destinations_without_sending(): void
    {
        Http::fake();
        $business = BusinessProfile::factory()->create();
        $workflow = AutomationWorkflow::factory()->create([
            'business_id' => $business->id,
            'trigger_config' => ['type' => 'keyword', 'conditions' => ['keywords' => ['price']]],
            'actions_config' => [[
                'type' => 'webhook',
                'url' => 'http://127.0.0.1/internal',
            ]],
        ]);
        $conversation = Conversation::factory()->create(['business_id' => $business->id]);
        Message::factory()->create([
            'conversation_id' => $conversation->id,
            'direction' => 'inbound',
            'content' => 'What is the price?',
        ]);

        app(AutomationEngine::class)->executeWorkflow($workflow, $conversation);

        Http::assertNothingSent();
    }

    public function test_workflow_webhook_sends_only_minimal_signed_context(): void
    {
        Http::fake(['https://1.1.1.1/hook' => Http::response(['ok' => true], 200)]);
        $business = BusinessProfile::factory()->create();
        $workflow = AutomationWorkflow::factory()->create([
            'business_id' => $business->id,
            'trigger_config' => ['type' => 'keyword', 'conditions' => ['keywords' => ['price']]],
            'actions_config' => [[
                'type' => 'webhook',
                'url' => 'https://1.1.1.1/hook',
            ]],
        ]);
        $conversation = Conversation::factory()->create([
            'business_id' => $business->id,
            'sender_email' => 'private@example.test',
            'sender_name' => 'Private Customer',
        ]);
        Message::factory()->create([
            'conversation_id' => $conversation->id,
            'direction' => 'inbound',
            'content' => 'What is the price?',
        ]);

        app(AutomationEngine::class)->executeWorkflow($workflow, $conversation);

        Http::assertSent(function ($request) use ($business, $conversation) {
            $payload = $request->data();
            $this->assertSame(['event_type', 'timestamp', 'business_id', 'conversation_id'], array_keys($payload));
            $this->assertSame($business->id, $payload['business_id']);
            $this->assertSame($conversation->id, $payload['conversation_id']);
            $this->assertNotContains('private@example.test', $payload);
            $this->assertTrue(app(WebhookService::class)->verifySignature(
                $payload,
                $request->header('X-Webhook-Signature')[0],
                (string) config('app.key')
            ));
            return true;
        });
    }

    public function test_webhook_secret_migration_encrypts_legacy_values_and_rolls_back(): void
    {
        $business = BusinessProfile::factory()->create();
        $webhookId = DB::table('webhooks')->insertGetId([
            'business_id' => $business->id,
            'url' => 'https://1.1.1.1/receiver',
            'events' => json_encode(['message.created']),
            'secret' => 'legacy-plaintext-key',
            'is_active' => true,
            'success_count' => 0,
            'failure_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration = require database_path('migrations/2026_10_08_000004_encrypt_webhook_secrets.php');
        $migration->up();
        $ciphertext = DB::table('webhooks')->where('id', $webhookId)->value('secret');
        $this->assertNotSame('legacy-plaintext-key', $ciphertext);
        $this->assertSame('legacy-plaintext-key', Crypt::decryptString($ciphertext));

        $migration->down();
        $this->assertSame('legacy-plaintext-key', DB::table('webhooks')->where('id', $webhookId)->value('secret'));
    }

    public function test_daily_analytics_calculates_metrics_from_business_scoped_data(): void
    {
        $business = BusinessProfile::factory()->create(['timezone' => 'UTC']);
        // Pin these records to midday so a long full-suite run cannot cross a
        // UTC/business-day boundary between fixture creation and calculation.
        $analyticsTimestamp = now()->setTime(12, 0, 0);
        $channel = Channel::factory()->create(['business_id' => $business->id]);
        $conversation = Conversation::factory()->create([
            'business_id' => $business->id,
            'channel_id' => $channel->id,
            'created_at' => $analyticsTimestamp,
        ]);
        Message::factory()->create([
            'conversation_id' => $conversation->id,
            'direction' => 'inbound',
            'is_ai' => false,
            'created_at' => $analyticsTimestamp,
        ]);
        Message::factory()->create([
            'conversation_id' => $conversation->id,
            'direction' => 'outbound',
            'is_ai' => true,
            'send_status' => 'sent',
            'confidence_score' => 0.9,
            'created_at' => $analyticsTimestamp->copy()->addSecond(),
        ]);
        AiMetric::create([
            'business_profile_id' => $business->id,
            'provider' => 'test-provider',
            'model' => 'test-model',
            'prompt_tokens' => 10,
            'completion_tokens' => 5,
            'total_tokens' => 15,
            'estimated_cost' => 0.01,
            'response_time_ms' => 100,
        ]);

        $service = app(AnalyticsService::class);
        $service->calculateDailyAnalytics($business->id, $analyticsTimestamp->toDateString());
        $service->calculateAiMetrics($business->id, $analyticsTimestamp->toDateString());

        $analytics = AnalyticsDaily::where('business_id', $business->id)->firstOrFail();
        $this->assertSame(1, $analytics->total_conversations);
        $this->assertSame(2, $analytics->total_messages);
        $this->assertSame(1, $analytics->ai_messages);
        $this->assertDatabaseHas('ai_metrics', [
            'business_profile_id' => $business->id,
            'provider' => 'test-provider',
            'total_tokens' => 15,
        ]);

        $secondBusiness = BusinessProfile::factory()->create();
        app(AnalyticsService::class)->calculateDailyAnalytics($secondBusiness->id, now()->toDateString());
        $this->assertDatabaseHas('analytics_daily', ['business_id' => $secondBusiness->id]);
    }

    public function test_email_campaign_send_and_recipient_jobs_are_atomically_claimed(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $user->id]);
        ContactConsent::create([
            'business_id' => $business->id,
            'channel' => 'email',
            'contact_identifier' => 'manual@example.com',
            'scope' => 'marketing',
            'status' => 'opted_in',
            'source' => 'admin',
            'captured_at' => now(),
        ]);
        $campaign = $this->makeEmailCampaign($business, ['manual@example.com']);
        Sanctum::actingAs($user);

        $this->withHeader('X-Business-Id', (string) $business->id)
            ->postJson("/api/email-campaigns/{$campaign->id}/send")->assertOk();
        $this->postJson("/api/email-campaigns/{$campaign->id}/send")->assertStatus(400);
        $this->assertSame(1, $campaign->recipients()->count());
        Queue::assertPushed(SendEmailCampaignMessage::class, 1);

        Queue::fake();
        Mail::fake();
        $recipient = $campaign->recipients()->first();
        $delivery = new SendEmailCampaignMessage($recipient->id);
        $delivery->handle();
        $delivery->handle();

        Mail::assertSent(EmailCampaignMail::class, 1);
        $this->assertSame('sent', $recipient->fresh()->status);
    }

    public function test_automated_sender_filter_uses_local_part_boundaries_in_all_contact_modes(): void
    {
        $business = BusinessProfile::factory()->create();
        foreach (['ahmedauto@example.com', 'autoparts@example.com', 'autoparts@customer.example', 'info@google.com'] as $email) {
            ContactConsent::create([
                'business_id' => $business->id, 'channel' => 'email', 'contact_identifier' => $email,
                'scope' => 'marketing', 'status' => 'opted_in', 'source' => 'form', 'captured_at' => now(),
            ]);
        }
        $gmail = Channel::factory()->create(['business_id' => $business->id, 'type' => 'gmail']);
        $social = Channel::factory()->create(['business_id' => $business->id, 'type' => 'instagram']);

        foreach ([
            [$gmail, 'ahmedauto@example.com'],
            [$gmail, 'autoparts@example.com'],
            [$gmail, 'noreply@example.com'],
            [$gmail, 'info@google.com'],
            [$social, 'noreply@customer.example'],
            [$social, 'autoparts@customer.example'],
        ] as [$channel, $email]) {
            Conversation::factory()->create([
                'business_id' => $business->id,
                'channel_id' => $channel->id,
                'sender_email' => $email,
            ]);
        }

        $service = app(EmailCampaignAudienceService::class);
        $gmailEmails = $service->resolveEmailsFromCriteria($business, ['mode' => 'gmail']);
        $contactEmails = $service->resolveEmailsFromCriteria($business, ['mode' => 'contacts']);

        $this->assertContains('ahmedauto@example.com', $gmailEmails);
        $this->assertContains('autoparts@example.com', $gmailEmails);
        $this->assertContains('info@google.com', $gmailEmails);
        $this->assertNotContains('noreply@example.com', $gmailEmails);
        $this->assertContains('autoparts@customer.example', $contactEmails);
        $this->assertNotContains('noreply@customer.example', $contactEmails);

        $manualEmails = $service->resolveEmailsFromCriteria($business, [
            'mode' => 'manual',
            'recipients' => ['ahmedauto@example.com', 'noreply@example.com'],
        ]);
        $this->assertContains('ahmedauto@example.com', $manualEmails);
        $this->assertNotContains('noreply@example.com', $manualEmails);
    }

    private function makeWebhook(string $url): Webhook
    {
        return Webhook::create([
            'business_id' => BusinessProfile::factory()->create()->id,
            'url' => $url,
            'events' => ['message.created'],
            'secret' => 'test-webhook-secret',
            'is_active' => true,
        ]);
    }

    private function makeEmailCampaign(BusinessProfile $business, array $recipients): EmailCampaign
    {
        return EmailCampaign::create([
            'business_id' => $business->id,
            'name' => 'Test campaign',
            'subject' => 'Test',
            'content' => '<p>Test</p>',
            'audience_criteria' => ['mode' => 'manual', 'recipients' => $recipients],
            'status' => 'draft',
        ]);
    }
}
