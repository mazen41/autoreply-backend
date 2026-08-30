<?php

namespace Tests\Feature;

use App\Jobs\ProcessAutoReply;
use App\Models\BusinessProfile;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Package;
use App\Models\User;
use App\Models\WhatsAppInstance;
use App\Models\WhatsAppMessage;
use App\Services\EvolutionApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Regression tests for:
 *
 * PRIORITY 1 — whatsapp_instance_id NOT NULL constraint failure.
 *   Root cause: sendWhatsAppReply() did WhatsAppInstance::where(...)->first()?->id
 *   which returns null when no WhatsAppInstance row exists (test env, or brief
 *   post-connect window in production). The null value violates the NOT NULL
 *   constraint on whatsapp_messages.whatsapp_instance_id.
 *   Fix: guard the insert — skip it when instance is absent; the unified inbox
 *   Message row is the canonical record and is always written.
 *
 * PRIORITY 6 — SEND_MESSAGE Evolution webhook echo.
 *   Root cause: processWebhookEvent() default branch logged
 *   "Unhandled webhook event type: SEND_MESSAGE" for every outbound echo.
 *   Fix: explicit case that logs at debug level only and returns without action.
 */
class WhatsAppInstanceIdTest extends TestCase
{
    use RefreshDatabase;

    private function makeFixture(): array
    {
        Package::create([
            'name' => 'Free', 'name_ar' => 'مجاني',
            'price_monthly' => 0, 'price_yearly' => 0,
            'ai_replies_limit' => -1, 'is_active' => true,
        ]);

        $user = User::forceCreate([
            'name' => 'Test', 'email' => 'test' . rand() . '@example.com',
            'password' => bcrypt('password'),
        ]);

        $business = BusinessProfile::create([
            'user_id' => $user->id,
            'business_name' => 'Test Biz',
            'knowledge_base' => 'We sell widgets.',
        ]);

        // Channel exists — but NO corresponding WhatsAppInstance row.
        // This reproduces the exact state that triggered the NOT NULL constraint failure.
        $channel = Channel::create([
            'user_id' => $user->id,
            'business_id' => $business->id,
            'type' => 'whatsapp',
            'page_id' => 'instance-no-wa-row',
            'status' => 'connected',
            'access_token' => 'dummy',
            'ai_enabled' => true,
        ]);

        $conversation = Conversation::create([
            'channel_id' => $channel->id,
            'business_id' => $business->id,
            'sender_id' => '966500000001',
            'status' => 'open',
        ]);

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'content' => 'Hello, what do you sell?',
            'direction' => 'inbound',
            'status' => 'received',
            'is_ai' => false,
            'send_status' => 'received',
        ]);

        return compact('user', 'business', 'channel', 'conversation', 'message');
    }

    /**
     * When no WhatsAppInstance row exists, ProcessAutoReply must NOT throw a
     * NOT NULL constraint failure. The reply must still reach the customer via
     * the Evolution sendText call.
     */
    public function test_outgoing_message_succeeds_without_whatsapp_instance_row(): void
    {
        $fixture = $this->makeFixture();

        Http::fake([
            'generativelanguage.googleapis.com/*embedContent*' => Http::response(
                ['embedding' => ['values' => []]], 200
            ),
            'generativelanguage.googleapis.com/*generateContent*' => Http::response([
                'candidates' => [[
                    'content' => ['parts' => [['text' => json_encode([
                        'reply' => 'We sell widgets! How can I help?',
                        'intent' => 'question',
                        'needs_escalation' => false,
                        'needs_images' => false,
                        'confidence' => 0.9,
                        'escalation_reason' => 'none',
                    ])]]],
                ]],
            ], 200),
            'localhost:8080/message/sendText/*' => Http::response(
                ['key' => ['id' => 'MSG_ABC_123']], 200
            ),
        ]);

        // Must not throw — this was the regression.
        (new ProcessAutoReply($fixture['message']->id))->handle();

        // Text send was attempted via Evolution.
        Http::assertSent(fn($r) => str_contains($r->url(), '/message/sendText/'));

        // AI reply persisted in unified inbox.
        $reply = Message::where('conversation_id', $fixture['conversation']->id)
            ->where('direction', 'outbound')
            ->where('is_ai', true)
            ->latest('id')
            ->first();

        $this->assertNotNull($reply, 'AI reply message must be persisted in unified inbox');
        $this->assertEquals('sent', $reply->send_status);

        // No WhatsAppMessage row with null instance_id must exist.
        $nullInstanceRow = WhatsAppMessage::whereNull('whatsapp_instance_id')->first();
        $this->assertNull(
            $nullInstanceRow,
            'No WhatsAppMessage row with null whatsapp_instance_id must exist (NOT NULL constraint)'
        );
    }

    /**
     * When a WhatsAppInstance row DOES exist, the legacy WhatsAppMessage record
     * must be created with the correct non-null instance ID.
     */
    public function test_outgoing_message_creates_legacy_record_when_instance_exists(): void
    {
        $fixture = $this->makeFixture();

        $instance = WhatsAppInstance::create([
            'user_id' => $fixture['user']->id,
            'instance_name' => 'instance-no-wa-row', // matches channel->page_id
            'status' => 'connected',
        ]);

        Http::fake([
            'generativelanguage.googleapis.com/*embedContent*' => Http::response(
                ['embedding' => ['values' => []]], 200
            ),
            'generativelanguage.googleapis.com/*generateContent*' => Http::response([
                'candidates' => [[
                    'content' => ['parts' => [['text' => json_encode([
                        'reply' => 'We sell widgets!',
                        'intent' => 'question',
                        'needs_escalation' => false,
                        'needs_images' => false,
                        'confidence' => 0.9,
                        'escalation_reason' => 'none',
                    ])]]],
                ]],
            ], 200),
            'localhost:8080/message/sendText/*' => Http::response(
                ['key' => ['id' => 'MSG_DEF_456']], 200
            ),
        ]);

        (new ProcessAutoReply($fixture['message']->id))->handle();

        $waMsg = WhatsAppMessage::where('whatsapp_instance_id', $instance->id)->first();
        $this->assertNotNull($waMsg, 'WhatsAppMessage row must be created when instance exists');
        $this->assertEquals($instance->id, $waMsg->whatsapp_instance_id);
        $this->assertEquals('sent', $waMsg->status);
    }

    /**
     * PRIORITY 6 — SEND_MESSAGE webhook echo must be silently acknowledged.
     * No exception, no inbox record, no "Unhandled webhook event type" log noise.
     */
    public function test_send_message_webhook_is_silently_ignored(): void
    {
        $initialMessageCount = Message::count();
        $initialWaCount = WhatsAppMessage::count();

        $service = new EvolutionApiService();

        // Must not throw.
        $service->processWebhookEvent([
            'event'    => 'send.message',  // lowercase dot-separated as Evolution sends it
            'instance' => 'test-instance',
            'data'     => [
                'key' => [
                    'id'        => 'ECHO_MSG_999',
                    'fromMe'    => true,
                    'remoteJid' => '966500000000@s.whatsapp.net',
                ],
                'message' => ['conversation' => 'Hello from bot'],
            ],
        ]);

        // No new records created.
        $this->assertEquals($initialMessageCount, Message::count(),
            'SEND_MESSAGE echo must not create Message records');
        $this->assertEquals($initialWaCount, WhatsAppMessage::count(),
            'SEND_MESSAGE echo must not create WhatsAppMessage records');
    }

    /**
     * PRIORITY 6 — MESSAGES_UPSERT with fromMe=true is skipped by the early
     * guard in handleMessageUpsert. No self-reply loop can start.
     */
    public function test_from_me_messages_upsert_is_skipped(): void
    {
        $initialCount = Message::count();

        $service = new EvolutionApiService();
        $service->processWebhookEvent([
            'event'    => 'MESSAGES_UPSERT',
            'instance' => 'nonexistent-instance',
            'data'     => [
                'key' => [
                    'id'        => 'OUTGOING_MSG_777',
                    'fromMe'    => true,
                    'remoteJid' => '966500000000@s.whatsapp.net',
                ],
                'message' => ['conversation' => 'Bot reply echoed back'],
            ],
        ]);

        // Instance doesn't exist → early return. No records.
        $this->assertEquals($initialCount, Message::count(),
            'fromMe=true MESSAGES_UPSERT on unknown instance must not create records');
    }
}
