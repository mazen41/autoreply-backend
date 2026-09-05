<?php

namespace Tests\Feature;

use App\Models\BusinessProfile;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\ConversationTag;
use App\Models\Message;
use App\Models\Sequence;
use App\Models\SequenceEnrollment;
use App\Models\SequenceStep;
use App\Models\SequenceStepExecution;
use App\Models\User;
use App\Services\ConditionEvaluationService;
use App\Services\SequenceExecutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConditionEvaluationServiceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private BusinessProfile $business;
    private Sequence $sequence;
    private Conversation $conversation;
    private SequenceEnrollment $enrollment;
    private SequenceStep $step;
    private ConditionEvaluationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->business = BusinessProfile::factory()->create();
        $this->user->business_id = $this->business->id;
        $this->user->save();

        $channel = Channel::factory()->create([
            'business_id' => $this->business->id,
            'type' => 'whatsapp',
            'status' => 'connected',
        ]);

        $this->sequence = Sequence::factory()->create([
            'business_id' => $this->business->id,
            'status' => 'active',
            'channel' => 'whatsapp',
        ]);

        $this->conversation = Conversation::factory()->create([
            'business_id' => $this->business->id,
            'channel_id' => $channel->id,
            'sender_id' => '+966501234567',
            'sender_name' => 'John Doe',
            'sender_email' => 'john@example.com',
            'status' => 'open',
            'requires_human' => false,
            'checkout_state' => [
                'order_id' => '1001',
                'status' => 'shipped',
                'total' => 250.00,
                'product_name' => 'Wireless Headphones',
            ],
        ]);

        $this->enrollment = SequenceEnrollment::factory()->create([
            'sequence_id' => $this->sequence->id,
            'conversation_id' => $this->conversation->id,
            'status' => 'active',
            'current_step' => 1,
            'started_at' => now()->subMinutes(10),
        ]);

        $this->step = SequenceStep::factory()->create([
            'sequence_id' => $this->sequence->id,
            'step_order' => 1,
            'step_type' => 'condition',
        ]);

        $this->service = app(ConditionEvaluationService::class);
    }

    /** @test */
    public function it_evaluates_customer_replied_correctly_tied_to_cycle()
    {
        // 1. Before customer replies: condition is FALSE
        $config = ['type' => 'customer_replied'];
        $resultBefore = $this->service->evaluate($this->enrollment, $this->step, $config);
        $this->assertFalse($resultBefore);

        // 2. Customer replies after cycle start: condition is TRUE
        Message::factory()->create([
            'conversation_id' => $this->conversation->id,
            'direction' => 'inbound',
            'content' => 'Hello, I have a question',
            'created_at' => now(),
        ]);

        $resultAfter = $this->service->evaluate($this->enrollment, $this->step, $config);
        $this->assertTrue($resultAfter);
    }

    /** @test */
    public function it_evaluates_customer_tags()
    {
        ConversationTag::create([
            'conversation_id' => $this->conversation->id,
            'tag' => 'VIP',
        ]);

        $hasVip = $this->service->evaluate($this->enrollment, $this->step, [
            'type' => 'customer_tag',
            'operator' => 'has_tag',
            'value' => 'VIP',
        ]);
        $this->assertTrue($hasVip);

        $hasWholesale = $this->service->evaluate($this->enrollment, $this->step, [
            'type' => 'customer_tag',
            'operator' => 'has_tag',
            'value' => 'Wholesale',
        ]);
        $this->assertFalse($hasWholesale);
    }

    /** @test */
    public function it_evaluates_message_content_and_ai_confidence()
    {
        Message::factory()->create([
            'conversation_id' => $this->conversation->id,
            'direction' => 'inbound',
            'content' => 'What is the price of this item?',
        ]);

        Message::factory()->create([
            'conversation_id' => $this->conversation->id,
            'direction' => 'outbound',
            'is_ai' => true,
            'content' => 'The price is SAR 250.',
            'confidence_score' => 0.95,
        ]);

        $textContainsPrice = $this->service->evaluate($this->enrollment, $this->step, [
            'type' => 'message_text',
            'operator' => 'contains',
            'value' => 'price',
        ]);
        $this->assertTrue($textContainsPrice);

        $highConfidence = $this->service->evaluate($this->enrollment, $this->step, [
            'type' => 'ai_confidence',
            'operator' => 'greater_than',
            'value' => 80,
        ]);
        $this->assertTrue($highConfidence);
    }

    /** @test */
    public function it_evaluates_order_details()
    {
        $hasOrder = $this->service->evaluate($this->enrollment, $this->step, ['type' => 'has_order']);
        $this->assertTrue($hasOrder);

        $statusShipped = $this->service->evaluate($this->enrollment, $this->step, [
            'type' => 'order_status',
            'operator' => 'equals',
            'value' => 'shipped',
        ]);
        $this->assertTrue($statusShipped);

        $totalGreaterThan100 = $this->service->evaluate($this->enrollment, $this->step, [
            'type' => 'order_total',
            'operator' => 'greater_than',
            'value' => 100,
        ]);
        $this->assertTrue($totalGreaterThan100);
    }

    /** @test */
    public function it_evaluates_channel_and_escalation()
    {
        $isWhatsapp = $this->service->evaluate($this->enrollment, $this->step, ['type' => 'channel_equals_whatsapp']);
        $this->assertTrue($isWhatsapp);

        $isNotEscalated = $this->service->evaluate($this->enrollment, $this->step, ['type' => 'is_not_escalated']);
        $this->assertTrue($isNotEscalated);
    }

    /** @test */
    public function it_supports_and_or_group_conditions()
    {
        ConversationTag::create([
            'conversation_id' => $this->conversation->id,
            'tag' => 'VIP',
        ]);

        // AND group: VIP tag AND order status shipped
        $andGroup = [
            'match' => 'all',
            'conditions' => [
                ['type' => 'customer_tag', 'operator' => 'has_tag', 'value' => 'VIP'],
                ['type' => 'order_status', 'operator' => 'equals', 'value' => 'shipped'],
            ],
        ];
        $this->assertTrue($this->service->evaluate($this->enrollment, $this->step, $andGroup));

        // OR group: VIP tag OR NonExistent tag
        $orGroup = [
            'match' => 'any',
            'conditions' => [
                ['type' => 'customer_tag', 'operator' => 'has_tag', 'value' => 'VIP'],
                ['type' => 'customer_tag', 'operator' => 'has_tag', 'value' => 'NonExistent'],
            ],
        ];
        $this->assertTrue($this->service->evaluate($this->enrollment, $this->step, $orGroup));
    }

    /** @test */
    public function it_executes_branching_routes_in_sequence_execution()
    {
        // Setup step 1: condition (VIP tag) -> if TRUE jump to step 3, if FALSE stop
        $step1 = SequenceStep::factory()->create([
            'sequence_id' => $this->sequence->id,
            'step_order' => 1,
            'step_type' => 'condition',
            'condition_config' => [
                'type' => 'customer_tag',
                'operator' => 'has_tag',
                'value' => 'VIP',
                'on_true' => 'jump',
                'true_step_order' => 3,
                'on_false' => 'stop',
            ],
        ]);

        $step2 = SequenceStep::factory()->create([
            'sequence_id' => $this->sequence->id,
            'step_order' => 2,
            'step_type' => 'message',
            'message' => 'Regular customer message',
        ]);

        $step3 = SequenceStep::factory()->create([
            'sequence_id' => $this->sequence->id,
            'step_order' => 3,
            'step_type' => 'message',
            'message' => 'VIP customer message',
        ]);

        ConversationTag::create([
            'conversation_id' => $this->conversation->id,
            'tag' => 'VIP',
        ]);

        $execution = SequenceStepExecution::factory()->create([
            'sequence_id' => $this->sequence->id,
            'sequence_enrollment_id' => $this->enrollment->id,
            'sequence_step_id' => $step1->id,
            'status' => 'pending',
        ]);

        $executionService = app(SequenceExecutionService::class);
        $executionService->executeStep($execution, $this->enrollment, $step1);

        $this->enrollment->refresh();
        $this->assertEquals(3, $this->enrollment->current_step);
    }
}
