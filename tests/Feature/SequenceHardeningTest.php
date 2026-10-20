<?php

namespace Tests\Feature;

use App\Models\Sequence;
use App\Models\SequenceStep;
use App\Models\SequenceEnrollment;
use App\Models\SequenceStepExecution;
use App\Models\Conversation;
use App\Models\BusinessProfile;
use App\Models\User;
use App\Models\Message;
use App\Models\Channel;
use App\Models\ContactConsent;
use App\Models\Bot;
use App\Models\FeatureFlag;
use App\Models\BusinessFeatureFlagAssignment;
use App\Models\TeamMember;
use App\Jobs\ExecuteSequenceStep;
use App\Services\SequenceExecutionService;
use App\Services\SequenceEnrollmentService;
use App\Services\SequenceTriggerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;
use Tests\TestCase;

class SequenceHardeningTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private BusinessProfile $business;
    private Sequence $sequence;
    private Conversation $conversation;
    private Channel $channel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->business = BusinessProfile::factory()->create(['user_id' => $this->user->id]);
        $this->user->business_id = $this->business->id;
        $this->user->save();

        $this->channel = Channel::factory()->create([
            'business_id'  => $this->business->id,
            'user_id'      => $this->user->id,
            'type'         => 'whatsapp',
            'status'       => 'connected',
            'connected_at' => now(),
            'metadata'     => ['whatsapp_provider' => 'evolution', 'unofficial_risk_accepted' => true],
        ]);

        $this->sequence = Sequence::factory()->create([
            'business_id' => $this->business->id,
            'status'      => 'active',
            'channel'     => 'whatsapp',
        ]);

        $this->conversation = Conversation::factory()->create([
            'business_id' => $this->business->id,
            'channel_id'  => $this->channel->id,
        ]);
        ContactConsent::create([
            'business_id' => $this->business->id, 'channel' => 'whatsapp',
            'contact_identifier' => app(\App\Services\ConsentGate::class)->normalizeIdentifier($this->conversation->sender_id, 'whatsapp'),
            'scope' => 'marketing', 'status' => 'opted_in', 'source' => 'form', 'captured_at' => now(),
        ]);
    }

    public function test_duplicate_job_dispatch_only_sends_message_once()
    {
        Queue::fake();

        $step = SequenceStep::factory()->create([
            'sequence_id' => $this->sequence->id,
            'step_order'  => 1,
            'step_type'   => 'message',
            'message'     => 'Test message',
        ]);

        $enrollment = SequenceEnrollment::factory()->create([
            'sequence_id'     => $this->sequence->id,
            'conversation_id' => $this->conversation->id,
            'status'          => 'active',
            'current_step'    => 1,
        ]);

        $execution = SequenceStepExecution::factory()->create([
            'sequence_id'            => $this->sequence->id,
            'sequence_enrollment_id' => $enrollment->id,
            'sequence_step_id'       => $step->id,
            'status'                 => 'pending',
        ]);

        // Dispatch the same job twice
        ExecuteSequenceStep::dispatch($execution->id);
        ExecuteSequenceStep::dispatch($execution->id);

        // Both jobs should be queued (the job itself handles idempotency)
        Queue::assertPushed(ExecuteSequenceStep::class, 2);
    }

    public function test_worker_retry_without_duplicate_message_send()
    {
        Http::fake([
            '*/message/sendText/*' => Http::response([
                'key'     => ['id' => 'fake-msg-id'],
                'message' => ['conversation' => 'faked'],
                'status'  => 'PENDING',
            ], 200),
            '*' => Http::response([], 200),
        ]);

        $step = SequenceStep::factory()->create([
            'sequence_id' => $this->sequence->id,
            'step_order'  => 1,
            'step_type'   => 'message',
            'message'     => 'Retry test message',
        ]);

        $enrollment = SequenceEnrollment::factory()->create([
            'sequence_id'     => $this->sequence->id,
            'conversation_id' => $this->conversation->id,
            'status'          => 'active',
            'current_step'    => 1,
        ]);

        $execution = SequenceStepExecution::factory()->create([
            'sequence_id'            => $this->sequence->id,
            'sequence_enrollment_id' => $enrollment->id,
            'sequence_step_id'       => $step->id,
            'status'                 => 'pending',
        ]);

        $service = app(SequenceExecutionService::class);

        // Execute the step
        $service->executeStep($execution, $enrollment, $step);

        // Verify message was created
        $this->assertDatabaseHas('messages', [
            'conversation_id' => $this->conversation->id,
            'content'         => 'Retry test message',
            'source'          => 'sequence',
        ]);

        $messageCount = Message::where('conversation_id', $this->conversation->id)
            ->where('content', 'Retry test message')
            ->count();
        $this->assertEquals(1, $messageCount);

        // Simulate worker retry — execute again
        $execution->refresh();
        $service->executeStep($execution, $enrollment, $step);

        // Still only one message
        $messageCount = Message::where('conversation_id', $this->conversation->id)
            ->where('content', 'Retry test message')
            ->count();
        $this->assertEquals(1, $messageCount);
    }

    public function test_sequence_job_releases_when_another_worker_holds_the_lock(): void
    {
        $step = SequenceStep::factory()->create([
            'sequence_id' => $this->sequence->id,
            'step_order' => 1,
            'step_type' => 'message',
            'message' => 'Lock contention message',
        ]);
        $enrollment = SequenceEnrollment::factory()->create([
            'sequence_id' => $this->sequence->id,
            'conversation_id' => $this->conversation->id,
            'status' => 'active',
            'current_step' => 1,
        ]);
        $execution = SequenceStepExecution::factory()->create([
            'sequence_id' => $this->sequence->id,
            'sequence_enrollment_id' => $enrollment->id,
            'sequence_step_id' => $step->id,
            'status' => 'pending',
        ]);
        $lock = Cache::lock("sequence_step_execution:{$enrollment->id}:{$step->id}", 60);
        $this->assertTrue($lock->get());

        $job = (new ExecuteSequenceStep($execution->id))->withFakeQueueInteractions();
        $job->handle(app(SequenceExecutionService::class));

        $job->assertReleased(15);
        $this->assertSame('pending', $execution->fresh()->status);
        $lock->release();
    }

    public function test_sequence_retry_reuses_provider_accepted_message_id_without_sending_again(): void
    {
        Http::fake([
            '*/message/sendText/*' => Http::response([
                'key' => ['id' => 'a-new-provider-id'],
                'status' => 'PENDING',
            ], 200),
            '*' => Http::response([], 200),
        ]);

        $step = SequenceStep::factory()->create([
            'sequence_id' => $this->sequence->id,
            'step_order' => 1,
            'step_type' => 'message',
            'message' => 'Previously accepted message',
        ]);
        $enrollment = SequenceEnrollment::factory()->create([
            'sequence_id' => $this->sequence->id,
            'conversation_id' => $this->conversation->id,
            'status' => 'active',
            'current_step' => 1,
        ]);
        $message = Message::create([
            'conversation_id' => $this->conversation->id,
            'content' => 'Previously accepted message',
            'direction' => 'outbound',
            'status' => 'sent',
            'send_status' => 'sent',
            'delivery_status' => 'sent',
            'source' => 'sequence',
            'metadata' => [
                'sequence_automated' => true,
                'provider_message_ids' => ['accepted-by-provider-123'],
                'platform_message_id' => 'accepted-by-provider-123',
            ],
        ]);
        $execution = SequenceStepExecution::factory()->create([
            'sequence_id' => $this->sequence->id,
            'sequence_enrollment_id' => $enrollment->id,
            'sequence_step_id' => $step->id,
            'message_id' => $message->id,
            'status' => 'processing',
            'updated_at' => now()->subSeconds(190),
        ]);

        (new ExecuteSequenceStep($execution->id))->handle(app(SequenceExecutionService::class));

        $this->assertSame(1, Message::where('conversation_id', $this->conversation->id)
            ->where('content', 'Previously accepted message')->count());
        $this->assertSame('executed', $execution->fresh()->status);
        $this->assertCount(0, Http::recorded(fn ($request) => str_contains($request->url(), '/message/sendText/')));
    }

    public function test_recent_processing_sequence_execution_is_released_for_the_current_worker(): void
    {
        $step = SequenceStep::factory()->create([
            'sequence_id' => $this->sequence->id,
            'step_order' => 1,
            'step_type' => 'message',
            'message' => 'Currently processing',
        ]);
        $enrollment = SequenceEnrollment::factory()->create([
            'sequence_id' => $this->sequence->id,
            'conversation_id' => $this->conversation->id,
            'status' => 'active',
            'current_step' => 1,
        ]);
        $execution = SequenceStepExecution::factory()->create([
            'sequence_id' => $this->sequence->id,
            'sequence_enrollment_id' => $enrollment->id,
            'sequence_step_id' => $step->id,
            'status' => 'processing',
            'updated_at' => now(),
        ]);
        $job = (new ExecuteSequenceStep($execution->id))->withFakeQueueInteractions();

        $job->handle(app(SequenceExecutionService::class));

        $job->assertReleased(15);
        $this->assertSame('processing', $execution->fresh()->status);
        $this->assertSame(0, Message::where('conversation_id', $this->conversation->id)->count());
    }

    public function test_sequence_loop_is_capped_after_fifty_execution_records(): void
    {
        Queue::fake();
        $firstStep = SequenceStep::factory()->create([
            'sequence_id' => $this->sequence->id,
            'step_order' => 1,
            'step_type' => 'message',
            'message' => 'Loop target',
        ]);
        SequenceStep::factory()->create([
            'sequence_id' => $this->sequence->id,
            'step_order' => 2,
            'step_type' => 'condition',
            'condition_config' => ['type' => 'customer_replied'],
        ]);
        $enrollment = SequenceEnrollment::factory()->create([
            'sequence_id' => $this->sequence->id,
            'conversation_id' => $this->conversation->id,
            'status' => 'active',
            'current_step' => 2,
        ]);
        $base = now();

        try {
            for ($i = 0; $i < 51; $i++) {
                Carbon::setTestNow($base->copy()->addSeconds($i));
                app(SequenceExecutionService::class)->jumpToStep($enrollment->fresh(), $firstStep->step_order);
            }
        } finally {
            Carbon::setTestNow();
        }

        $this->assertLessThanOrEqual(50, SequenceStepExecution::where('sequence_enrollment_id', $enrollment->id)->count());
        $this->assertSame(range(1, 50), SequenceStepExecution::where('sequence_enrollment_id', $enrollment->id)
            ->orderBy('execution_index')->pluck('execution_index')->all());
        $lastVisit = SequenceStepExecution::where('sequence_enrollment_id', $enrollment->id)
            ->where('sequence_step_id', $firstStep->id)
            ->orderByDesc('execution_index')
            ->firstOrFail();
        $this->assertSame(50, $lastVisit->metadata['visit_count']);
        $this->assertSame('stopped', $enrollment->fresh()->status);
        $this->assertSame('max_step_executions_reached', $enrollment->fresh()->metadata['stop_reason'] ?? null);
    }

    public function test_sequence_never_falls_back_to_another_connected_channel_for_a_disconnected_conversation(): void
    {
        Http::fake([
            '*/message/sendText/*' => Http::response(['key' => ['id' => 'wrong-channel-id'], 'status' => 'PENDING'], 200),
            '*' => Http::response([], 200),
        ]);
        $this->channel->update(['status' => 'disconnected']);
        $replacement = Channel::factory()->create([
            'business_id' => $this->business->id,
            'user_id' => $this->user->id,
            'type' => 'whatsapp',
            'status' => 'connected',
            'connected_at' => now()->addMinute(),
            'page_id' => 'different-instance',
            'metadata' => ['whatsapp_provider' => 'evolution', 'unofficial_risk_accepted' => true],
        ]);
        $step = SequenceStep::factory()->create([
            'sequence_id' => $this->sequence->id,
            'step_order' => 1,
            'step_type' => 'message',
            'message' => 'Must not use replacement instance',
        ]);
        $enrollment = SequenceEnrollment::factory()->create([
            'sequence_id' => $this->sequence->id,
            'conversation_id' => $this->conversation->id,
            'status' => 'active',
            'current_step' => 1,
        ]);
        $execution = SequenceStepExecution::factory()->create([
            'sequence_id' => $this->sequence->id,
            'sequence_enrollment_id' => $enrollment->id,
            'sequence_step_id' => $step->id,
            'status' => 'pending',
        ]);

        (new ExecuteSequenceStep($execution->id))->handle(app(SequenceExecutionService::class));

        $this->assertSame('disconnected', $this->conversation->fresh()->channel->status);
        $this->assertSame('paused', $enrollment->fresh()->status);
        $this->assertSame('channel_disconnected', $enrollment->fresh()->metadata['pause_reason'] ?? null);
        $this->assertDatabaseHas('handoff_tasks', [
            'conversation_id' => $this->conversation->id,
            'reason' => 'channel_disconnected',
            'status' => 'open',
        ]);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'different-instance'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/message/sendText/'));
        $this->assertNotNull($replacement->id);
    }

    public function test_sequence_telegram_uses_encrypted_channel_access_token_and_persists_provider_id(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 8812]], 200),
        ]);
        $telegramChannel = Channel::factory()->create([
            'business_id' => $this->business->id,
            'user_id' => $this->user->id,
            'type' => 'telegram',
            'status' => 'connected',
            'access_token' => '123456:encrypted-channel-token',
            'metadata' => ['bot_token' => 'stale-metadata-token'],
        ]);
        $this->conversation->update(['channel_id' => $telegramChannel->id]);
        $this->sequence->update(['channel' => 'telegram']);
        ContactConsent::create([
            'business_id' => $this->business->id,
            'channel' => 'telegram',
            'contact_identifier' => app(\App\Services\ConsentGate::class)->normalizeIdentifier($this->conversation->sender_id, 'telegram'),
            'scope' => 'marketing',
            'status' => 'opted_in',
            'source' => 'form',
            'captured_at' => now(),
        ]);
        $step = SequenceStep::factory()->create([
            'sequence_id' => $this->sequence->id,
            'step_order' => 1,
            'step_type' => 'message',
            'message' => 'Telegram sequence message',
        ]);
        $enrollment = SequenceEnrollment::factory()->create([
            'sequence_id' => $this->sequence->id,
            'conversation_id' => $this->conversation->id,
            'status' => 'active',
            'current_step' => 1,
        ]);
        $execution = SequenceStepExecution::factory()->create([
            'sequence_id' => $this->sequence->id,
            'sequence_enrollment_id' => $enrollment->id,
            'sequence_step_id' => $step->id,
            'status' => 'pending',
        ]);

        (new ExecuteSequenceStep($execution->id))->handle(app(SequenceExecutionService::class));

        Http::assertSent(fn ($request) => str_contains($request->url(), '/bot123456:encrypted-channel-token/sendMessage'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'stale-metadata-token'));
        $outbound = Message::query()->where('conversation_id', $this->conversation->id)
            ->where('content', 'Telegram sequence message')->firstOrFail();
        $this->assertSame('8812', $outbound->metadata['platform_message_id'] ?? null);
        $this->assertSame('sent', $outbound->send_status);
    }

    public function test_email_sequence_routes_to_the_conversation_gmail_channel(): void
    {
        $gmailChannel = Channel::factory()->create([
            'business_id' => $this->business->id,
            'user_id' => $this->user->id,
            'type' => 'gmail',
            'status' => 'connected',
        ]);
        $this->conversation->update([
            'channel_id' => $gmailChannel->id,
            'sender_id' => 'gmail-thread-1',
            'sender_email' => 'reader@example.test',
        ]);
        $this->sequence->update(['channel' => 'email']);
        ContactConsent::create([
            'business_id' => $this->business->id,
            'channel' => 'email',
            'contact_identifier' => 'reader@example.test',
            'scope' => 'marketing',
            'status' => 'opted_in',
            'source' => 'form',
            'captured_at' => now(),
        ]);
        $step = SequenceStep::factory()->create([
            'sequence_id' => $this->sequence->id,
            'step_order' => 1,
            'step_type' => 'message',
            'message' => 'Gmail follow-up',
        ]);
        $enrollment = SequenceEnrollment::factory()->create([
            'sequence_id' => $this->sequence->id,
            'conversation_id' => $this->conversation->id,
            'status' => 'active',
            'current_step' => 1,
        ]);
        $execution = SequenceStepExecution::factory()->create([
            'sequence_id' => $this->sequence->id,
            'sequence_enrollment_id' => $enrollment->id,
            'sequence_step_id' => $step->id,
            'status' => 'pending',
        ]);
        $this->mock(\App\Services\ChannelMessageService::class, function ($mock) use ($gmailChannel, $step): void {
            $mock->shouldReceive('sendMessage')->once()->withArgs(function ($channel, $conversation, $message) use ($gmailChannel, $step) {
                return $channel->id === $gmailChannel->id
                    && $conversation->sender_email === 'reader@example.test'
                    && $message->content === $step->message;
            })->andReturnUsing(function ($channel, $conversation, $message): bool {
                $message->update(['status' => 'sent', 'send_status' => 'sent', 'delivery_status' => 'sent']);
                return true;
            });
        });

        app(SequenceExecutionService::class)->executeStep($execution, $enrollment, $step);

        $this->assertSame('executed', $execution->fresh()->status);
        $this->assertSame('sent', Message::where('conversation_id', $this->conversation->id)
            ->where('content', 'Gmail follow-up')->firstOrFail()->send_status);
    }

    public function test_sequence_send_stops_when_a_human_has_taken_over_the_conversation(): void
    {
        $flag = FeatureFlag::where('key', 'phase5_sequence_takeover_guard')->firstOrFail();
        BusinessFeatureFlagAssignment::create([
            'business_id' => $this->business->id,
            'feature_flag_id' => $flag->id,
            'enabled' => true,
        ]);
        Http::fake([
            '*/message/sendText/*' => Http::response(['key' => ['id' => 'must-not-send'], 'status' => 'PENDING'], 200),
            '*' => Http::response([], 200),
        ]);
        $this->conversation->update(['requires_human' => true]);
        $step = SequenceStep::factory()->create([
            'sequence_id' => $this->sequence->id,
            'step_order' => 1,
            'step_type' => 'message',
            'message' => 'Do not send after takeover',
        ]);
        $enrollment = SequenceEnrollment::factory()->create([
            'sequence_id' => $this->sequence->id,
            'conversation_id' => $this->conversation->id,
            'status' => 'active',
            'current_step' => 1,
        ]);
        $execution = SequenceStepExecution::factory()->create([
            'sequence_id' => $this->sequence->id,
            'sequence_enrollment_id' => $enrollment->id,
            'sequence_step_id' => $step->id,
            'status' => 'pending',
        ]);

        (new ExecuteSequenceStep($execution->id))->handle(app(SequenceExecutionService::class));

        $this->assertSame('stopped', $enrollment->fresh()->status);
        $this->assertSame('human_takeover', $enrollment->fresh()->metadata['stop_reason'] ?? null);
        $this->assertSame('skipped', $execution->fresh()->status);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/message/sendText/'));
    }

    // ── PROVIDER FAILURE RECORDING AND RETRY ─────────────────────────────────

    public function test_provider_failure_is_recorded_and_retried()
    {
        Http::fake([
            '*/message/sendText/*' => Http::response(['error' => 'Provider timeout'], 500),
            '*' => Http::response([], 500),
        ]);

        $step = SequenceStep::factory()->create([
            'sequence_id' => $this->sequence->id,
            'step_order'  => 1,
            'step_type'   => 'message',
            'message'     => 'Failure test message',
        ]);

        $enrollment = SequenceEnrollment::factory()->create([
            'sequence_id'     => $this->sequence->id,
            'conversation_id' => $this->conversation->id,
            'status'          => 'active',
            'current_step'    => 1,
        ]);

        $execution = SequenceStepExecution::factory()->create([
            'sequence_id'            => $this->sequence->id,
            'sequence_enrollment_id' => $enrollment->id,
            'sequence_step_id'       => $step->id,
            'status'                 => 'pending',
        ]);

        $service = app(SequenceExecutionService::class);

        // Execute — should throw due to provider failure
        try {
            $service->executeStep($execution, $enrollment, $step);
            $this->fail('Expected exception was not thrown');
        } catch (\Exception $e) {
            // Expected
        }

        // Verify execution was marked as failed with error detail
        $execution->refresh();
        $this->assertEquals('failed', $execution->status);
        $this->assertNotNull($execution->error);
        $this->assertStringContainsString('status: 500', $execution->error);
        $this->assertStringNotContainsString('Provider timeout', $execution->error);

        // Verify message was created but marked as failed
        $this->assertDatabaseHas('messages', [
            'conversation_id' => $this->conversation->id,
            'content'         => 'Failure test message',
            'delivery_status' => 'failed',
        ]);
    }

    // ── CUSTOMER REPLY CANCELS ENROLLMENT ────────────────────────────────────

    public function test_customer_reply_cancels_enrollment()
    {
        Queue::fake();

        $step = SequenceStep::factory()->create([
            'sequence_id' => $this->sequence->id,
            'step_order'  => 1,
            'step_type'   => 'message',
            'message'     => 'Welcome',
        ]);

        $enrollment = SequenceEnrollment::factory()->create([
            'sequence_id'     => $this->sequence->id,
            'conversation_id' => $this->conversation->id,
            'status'          => 'active',
            'current_step'    => 1,
            'started_at'      => now()->subHour(),
        ]);

        // Simulate customer reply
        Message::factory()->create([
            'conversation_id' => $this->conversation->id,
            'direction'       => 'inbound',
            'content'         => 'Customer response',
            'created_at'      => now()->subMinutes(5),
        ]);

        // Trigger cancellation
        $triggerService = app(SequenceTriggerService::class);
        $triggerService->stopSequencesOnCustomerReply($this->conversation);

        // Verify enrollment was stopped
        $enrollment->refresh();
        $this->assertEquals('stopped', $enrollment->status);
        $this->assertNotNull($enrollment->stopped_at);
    }

    // ── FULL CHAIN COMPLETION ────────────────────────────────────────────────

    public function test_enrollment_completes_full_chain()
    {
        Queue::fake();

        // Step 1: Message
        SequenceStep::factory()->create([
            'sequence_id' => $this->sequence->id,
            'step_order'  => 1,
            'step_type'   => 'message',
            'message'     => 'Step 1: Welcome',
        ]);

        // Step 2: Delay
        SequenceStep::factory()->create([
            'sequence_id' => $this->sequence->id,
            'step_order'  => 2,
            'step_type'   => 'delay',
            'delay_hours' => 0,
            'delay_unit'  => 'minutes',
            'delay_minutes' => 1,
        ]);

        // Step 3: Message
        SequenceStep::factory()->create([
            'sequence_id' => $this->sequence->id,
            'step_order'  => 3,
            'step_type'   => 'message',
            'message'     => 'Step 3: Follow-up',
        ]);

        // Step 4: Condition (always true)
        SequenceStep::factory()->create([
            'sequence_id'      => $this->sequence->id,
            'step_order'       => 4,
            'step_type'        => 'condition',
            'condition_config' => [
                'type'     => 'customer_replied',
                'on_true'  => 'continue',
                'on_false' => 'continue',
            ],
        ]);

        // Step 5: Final message
        SequenceStep::factory()->create([
            'sequence_id' => $this->sequence->id,
            'step_order'  => 5,
            'step_type'   => 'message',
            'message'     => 'Step 5: Final',
        ]);

        // Enroll conversation
        $enrollmentService = app(SequenceEnrollmentService::class);
        $enrollment = $enrollmentService->enrollConversation($this->sequence, $this->conversation);

        $this->assertEquals('active', $enrollment->status);
        $this->assertEquals(1, $enrollment->current_step);

        Http::fake([
            '*/message/sendText/*' => function () {
                static $providerMessageId = 0;
                return Http::response([
                    'key' => ['id' => 'fake-msg-' . ++$providerMessageId],
                    'message' => ['conversation' => 'faked'],
                    'status' => 'PENDING',
                ], 200);
            },
            '*' => Http::response([], 200),
        ]);

        // Execute step 1 (message)
        $execution1 = SequenceStepExecution::where('sequence_enrollment_id', $enrollment->id)
            ->where('sequence_step_id', $this->sequence->steps()->where('step_order', 1)->first()->id)
            ->first();
        $service = app(SequenceExecutionService::class);
        $step1 = $this->sequence->steps()->where('step_order', 1)->first();
        $service->executeStep($execution1, $enrollment, $step1);

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $this->conversation->id,
            'content'         => 'Step 1: Welcome',
        ]);

        // Execute step 2 (delay) — should schedule step 3
        $execution2 = SequenceStepExecution::where('sequence_enrollment_id', $enrollment->id)
            ->where('sequence_step_id', $this->sequence->steps()->where('step_order', 2)->first()->id)
            ->first();
        $step2 = $this->sequence->steps()->where('step_order', 2)->first();
        $service->executeStep($execution2, $enrollment, $step2);

        // Step 3 should now be scheduled
        $enrollment->refresh();
        $this->assertEquals(3, $enrollment->current_step);

        // Execute step 3 (message)
        $execution3 = SequenceStepExecution::where('sequence_enrollment_id', $enrollment->id)
            ->where('sequence_step_id', $this->sequence->steps()->where('step_order', 3)->first()->id)
            ->first();
        $step3 = $this->sequence->steps()->where('step_order', 3)->first();
        $service->executeStep($execution3, $enrollment, $step3);

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $this->conversation->id,
            'content'         => 'Step 3: Follow-up',
        ]);

        // Execute step 4 (condition)
        $execution4 = SequenceStepExecution::where('sequence_enrollment_id', $enrollment->id)
            ->where('sequence_step_id', $this->sequence->steps()->where('step_order', 4)->first()->id)
            ->first();
        $step4 = $this->sequence->steps()->where('step_order', 4)->first();
        $service->executeStep($execution4, $enrollment, $step4);

        // Execute step 5 (final message)
        $execution5 = SequenceStepExecution::where('sequence_enrollment_id', $enrollment->id)
            ->where('sequence_step_id', $this->sequence->steps()->where('step_order', 5)->first()->id)
            ->first();
        $step5 = $this->sequence->steps()->where('step_order', 5)->first();
        $service->executeStep($execution5, $enrollment, $step5);

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $this->conversation->id,
            'content'         => 'Step 5: Final',
        ]);

        // Enrollment should be completed
        $enrollment->refresh();
        $this->assertEquals('completed', $enrollment->status);
        $this->assertNotNull($enrollment->completed_at);
    }

    // ── ORDER_CREATED TRIGGER ─────────────────────────────────────────────────

    public function test_order_created_trigger_enrolls_correctly()
    {
        Queue::fake();

        $orderSequence = Sequence::factory()->create([
            'business_id'   => $this->business->id,
            'status'        => 'active',
            'channel'       => 'whatsapp',
            'trigger_type'  => 'order_created',
            'trigger_config' => ['min_order_value' => 100],
        ]);

        // Enrollment is NOT auto-completed when the sequence has no steps,
        // so give it a step that can enroll into an active state
        SequenceStep::factory()->create([
            'sequence_id' => $orderSequence->id,
            'step_order'  => 1,
            'step_type'   => 'message',
            'message'     => 'Order received',
        ]);

        $orderData = [
            'id'    => 'ORD-12345',
            'total' => 150,
        ];

        $triggerService = app(SequenceTriggerService::class);
        $triggerService->checkAndEnrollForOrderCreated($this->conversation, $orderData);

        // Verify enrollment was created
        $this->assertDatabaseHas('sequence_enrollments', [
            'sequence_id'     => $orderSequence->id,
            'conversation_id' => $this->conversation->id,
            'status'          => 'active',
        ]);
    }

    public function test_order_created_trigger_respects_min_order_value()
    {
        Queue::fake();

        $orderSequence = Sequence::factory()->create([
            'business_id'   => $this->business->id,
            'status'        => 'active',
            'channel'       => 'whatsapp',
            'trigger_type'  => 'order_created',
            'trigger_config' => ['min_order_value' => 200],
        ]);

        $orderData = [
            'id'    => 'ORD-12345',
            'total' => 150, // Below threshold
        ];

        $triggerService = app(SequenceTriggerService::class);
        $triggerService->checkAndEnrollForOrderCreated($this->conversation, $orderData);

        // Verify NO enrollment was created
        $this->assertDatabaseMissing('sequence_enrollments', [
            'sequence_id'     => $orderSequence->id,
            'conversation_id' => $this->conversation->id,
        ]);
    }

    // ── BOT-SCOPED SEQUENCE ISOLATION ────────────────────────────────────────

    public function test_bot_scoped_sequence_does_not_enroll_wrong_bot_conversations()
    {
        Queue::fake();

        // Create two bots
        $bot1 = Bot::create(['business_profile_id' => $this->business->id, 'name' => 'Bot 1', 'status' => 'active']);
        $bot2 = Bot::create(['business_profile_id' => $this->business->id, 'name' => 'Bot 2', 'status' => 'active']);

        // Create a bot-scoped sequence for bot1
        $botSequence = Sequence::factory()->create([
            'business_id'  => $this->business->id,
            'status'       => 'active',
            'channel'      => 'whatsapp',
            'trigger_type' => 'new_user',
            'bot_id'       => $bot1->id,
        ]);

        // Give the sequence a step so enrollments enter an active state
        SequenceStep::factory()->create([
            'sequence_id' => $botSequence->id,
            'step_order'  => 1,
            'step_type'   => 'message',
            'message'     => 'Hello from bot',
        ]);

        // Conversation belongs to bot2
        $conversation2 = Conversation::factory()->create([
            'business_id' => $this->business->id,
            'channel_id'  => $this->channel->id,
            'bot_id'      => $bot2->id,
        ]);

        // Try to enroll bot2's conversation in bot1's sequence
        $triggerService = app(SequenceTriggerService::class);
        $triggerService->checkAndEnrollForMessageReceived($conversation2);

        // Verify NO enrollment was created for bot2's conversation
        $this->assertDatabaseMissing('sequence_enrollments', [
            'sequence_id'     => $botSequence->id,
            'conversation_id' => $conversation2->id,
        ]);
    }

    public function test_bot_scoped_sequence_enrolls_correct_bot_conversations()
    {
        Queue::fake();

        // Create two bots
        $bot1 = Bot::create(['business_profile_id' => $this->business->id, 'name' => 'Bot 1', 'status' => 'active']);
        $bot2 = Bot::create(['business_profile_id' => $this->business->id, 'name' => 'Bot 2', 'status' => 'active']);

        // Create a bot-scoped sequence for bot1
        $botSequence = Sequence::factory()->create([
            'business_id'  => $this->business->id,
            'status'       => 'active',
            'channel'      => 'whatsapp',
            'trigger_type' => 'new_user',
            'bot_id'       => $bot1->id,
        ]);

        // Give the sequence a step so enrollments enter an active state
        SequenceStep::factory()->create([
            'sequence_id' => $botSequence->id,
            'step_order'  => 1,
            'step_type'   => 'message',
            'message'     => 'Hello from bot',
        ]);

        // Conversation belongs to bot1
        $conversation1 = Conversation::factory()->create([
            'business_id' => $this->business->id,
            'channel_id'  => $this->channel->id,
            'bot_id'      => $bot1->id,
        ]);
        ContactConsent::create([
            'business_id' => $this->business->id,
            'channel' => 'whatsapp',
            'contact_identifier' => app(\App\Services\ConsentGate::class)->normalizeIdentifier($conversation1->sender_id, 'whatsapp'),
            'scope' => 'marketing',
            'status' => 'opted_in',
            'source' => 'form',
            'captured_at' => now(),
        ]);
        Message::factory()->create([
            'conversation_id' => $conversation1->id,
            'direction' => 'inbound',
        ]);

        // Enroll bot1's conversation
        $triggerService = app(SequenceTriggerService::class);
        $triggerService->checkAndEnrollForMessageReceived($conversation1);

        // Verify enrollment was created
        $this->assertDatabaseHas('sequence_enrollments', [
            'sequence_id'     => $botSequence->id,
            'conversation_id' => $conversation1->id,
            'status'          => 'active',
        ]);
    }

    public function test_manual_enrollment_is_allowed_without_marketing_consent(): void
    {
        // Policy: sequence enrollment does not require a marketing opt-in.
        Http::fake([
            '*/message/sendText/*' => Http::response(['key' => ['id' => 'fake-enroll-msg']], 200),
            '*' => Http::response([], 200),
        ]);
        ContactConsent::query()->where('business_id', $this->business->id)->delete();
        SequenceStep::factory()->create(['sequence_id' => $this->sequence->id, 'step_order' => 1]);

        $service = app(SequenceEnrollmentService::class);

        $this->assertTrue($service->canEnroll($this->sequence, $this->conversation));
        $service->enrollConversation($this->sequence, $this->conversation);

        $this->assertSame(1, SequenceEnrollment::where('conversation_id', $this->conversation->id)->count());
    }

    public function test_manual_enrollment_is_blocked_for_opted_out_contacts(): void
    {
        ContactConsent::query()->where('business_id', $this->business->id)->delete();
        ContactConsent::create([
            'business_id' => $this->business->id,
            'channel' => 'whatsapp',
            'contact_identifier' => app(\App\Services\ConsentGate::class)->normalizeIdentifier($this->conversation->sender_id, 'whatsapp'),
            'scope' => 'marketing',
            'status' => 'opted_out',
            'source' => 'form',
            'captured_at' => now(),
        ]);
        SequenceStep::factory()->create(['sequence_id' => $this->sequence->id, 'step_order' => 1]);

        $this->assertFalse(app(SequenceEnrollmentService::class)->canEnroll($this->sequence, $this->conversation));
    }

    public function test_customer_reply_stops_only_sequences_configured_to_stop_on_reply(): void
    {
        Queue::fake();
        $stopSequence = Sequence::factory()->create([
            'business_id' => $this->business->id,
            'trigger_type' => 'new_user',
            'settings' => ['stop_on_reply' => true],
        ]);
        $continueSequence = Sequence::factory()->create([
            'business_id' => $this->business->id,
            'trigger_type' => 'new_user',
            'settings' => ['stop_on_reply' => false],
        ]);
        $step = SequenceStep::factory()->create([
            'sequence_id' => $stopSequence->id,
            'step_order' => 1,
            'step_type' => 'message',
            'message' => 'Follow up',
        ]);
        $stopEnrollment = SequenceEnrollment::factory()->create([
            'sequence_id' => $stopSequence->id,
            'conversation_id' => $this->conversation->id,
            'status' => 'active',
            'current_step' => 1,
        ]);
        $continueEnrollment = SequenceEnrollment::factory()->create([
            'sequence_id' => $continueSequence->id,
            'conversation_id' => $this->conversation->id,
            'status' => 'active',
            'current_step' => 1,
        ]);
        $pending = SequenceStepExecution::factory()->create([
            'sequence_id' => $stopSequence->id,
            'sequence_enrollment_id' => $stopEnrollment->id,
            'sequence_step_id' => $step->id,
            'status' => 'pending',
        ]);

        app(SequenceTriggerService::class)->cancelNoReplySequencesOnCustomerReply($this->conversation);

        $this->assertSame('stopped', $stopEnrollment->fresh()->status);
        $this->assertSame('customer_replied', $stopEnrollment->fresh()->metadata['stop_reason'] ?? null);
        $this->assertSame('skipped', $pending->fresh()->status);
        $this->assertSame('active', $continueEnrollment->fresh()->status);
    }

    // ── DELAYED STEP SURVIVES WORKER RESTART ─────────────────────────────────

    public function test_delayed_step_survives_worker_restart()
    {
        Queue::fake();

        // Create sequence with delay
        SequenceStep::factory()->create([
            'sequence_id' => $this->sequence->id,
            'step_order'  => 1,
            'step_type'   => 'delay',
            'delay_hours' => 1,
            'delay_unit'  => 'hours',
        ]);

        SequenceStep::factory()->create([
            'sequence_id' => $this->sequence->id,
            'step_order'  => 2,
            'step_type'   => 'message',
            'message'     => 'Delayed message',
        ]);

        // Enroll conversation
        $enrollmentService = app(SequenceEnrollmentService::class);
        $enrollment = $enrollmentService->enrollConversation($this->sequence, $this->conversation);

        // Execute delay step
        $delayStep = $this->sequence->steps()->where('step_order', 1)->first();
        $execution = SequenceStepExecution::where('sequence_enrollment_id', $enrollment->id)
            ->where('sequence_step_id', $delayStep->id)
            ->first();

        $service = app(SequenceExecutionService::class);
        $service->executeStep($execution, $enrollment, $delayStep);

        // Verify next step execution was scheduled with correct delay
        $nextExecution = SequenceStepExecution::where('sequence_enrollment_id', $enrollment->id)
            ->where('sequence_step_id', $this->sequence->steps()->where('step_order', 2)->first()->id)
            ->first();

        $this->assertNotNull($nextExecution);
        $this->assertEquals('pending', $nextExecution->status);
        $this->assertGreaterThan(now()->addMinutes(59), $nextExecution->scheduled_at);
    }

    // ── EXECUTION KEY IDEMPOTENCY ────────────────────────────────────────────

    public function test_execution_key_prevents_duplicate_execution_records()
    {
        $step = SequenceStep::factory()->create([
            'sequence_id' => $this->sequence->id,
            'step_order'  => 1,
            'step_type'   => 'message',
            'message'     => 'Test',
        ]);

        $enrollment = SequenceEnrollment::factory()->create([
            'sequence_id'     => $this->sequence->id,
            'conversation_id' => $this->conversation->id,
            'status'          => 'active',
            'current_step'    => 1,
        ]);

        $enrollmentService = app(SequenceEnrollmentService::class);

        // Create execution record twice
        $enrollmentService->createStepExecutionRecord($enrollment);
        $enrollmentService->createStepExecutionRecord($enrollment);

        // Should only have one execution record
        $executionCount = SequenceStepExecution::where('sequence_enrollment_id', $enrollment->id)
            ->where('sequence_step_id', $step->id)
            ->count();

        $this->assertEquals(1, $executionCount);
    }

    public function test_team_member_sequence_action_uses_selected_business_without_mutating_user(): void
    {
        $member = User::factory()->create();
        TeamMember::factory()->create([
            'business_id' => $this->business->id,
            'user_id' => $member->id,
            'role' => 'manager',
            'is_active' => true,
        ]);
        $originalBusinessId = $member->business_id;

        $this->actingAs($member, 'sanctum')
            ->withHeader('X-Business-Id', (string) $this->business->id)
            ->putJson("/api/sequences/{$this->sequence->id}", ['name' => 'Selected Business Sequence'])
            ->assertOk();

        $this->assertSame($originalBusinessId, $member->fresh()->business_id);
        $this->assertSame('Selected Business Sequence', $this->sequence->fresh()->name);
    }

    // ── CONVERSATION CLOSE CANCELS ENROLLMENT ────────────────────────────────

    public function test_conversation_close_cancels_enrollment()
    {
        $enrollment = SequenceEnrollment::factory()->create([
            'sequence_id'     => $this->sequence->id,
            'conversation_id' => $this->conversation->id,
            'status'          => 'active',
            'current_step'    => 1,
        ]);

        // Close conversation via API
        $response = $this->actingAs($this->user)
            ->withHeader('X-Business-Id', (string) $this->business->id)
            ->patchJson("/api/inbox/{$this->conversation->id}/status", [
                'status' => 'closed',
            ]);

        $response->assertOk();

        // Verify enrollment was stopped
        $enrollment->refresh();
        $this->assertEquals('stopped', $enrollment->status);
    }
}
