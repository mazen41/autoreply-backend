<?php

namespace Tests\Feature;

use App\Models\Sequence;
use App\Models\SequenceStep;
use App\Models\SequenceEnrollment;
use App\Models\Conversation;
use App\Models\BusinessProfile;
use App\Models\User;
use App\Services\SequenceEnrollmentService;
use App\Services\SequenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SequenceEnrollmentServiceTest extends TestCase
{
    use RefreshDatabase;

    private SequenceEnrollmentService $enrollmentService;
    private SequenceService $sequenceService;
    private User $user;
    private BusinessProfile $business;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->enrollmentService = new SequenceEnrollmentService();
        $this->sequenceService = new SequenceService();
        
        $this->user = User::factory()->create();
        $this->business = BusinessProfile::factory()->create();
        $this->user->business_id = $this->business->id;
        $this->user->save();
    }

    public function test_enroll_conversation()
    {
        $sequence = Sequence::factory()->create([
            'business_id' => $this->business->id,
            'status' => 'active',
        ]);

        SequenceStep::factory()->create([
            'sequence_id' => $sequence->id,
            'step_order' => 1,
        ]);

        $conversation = Conversation::factory()->create([
            'business_id' => $this->business->id,
        ]);

        $enrollment = $this->enrollmentService->enrollConversation($sequence, $conversation);

        $this->assertInstanceOf(SequenceEnrollment::class, $enrollment);
        $this->assertEquals('active', $enrollment->status);
        $this->assertEquals($sequence->id, $enrollment->sequence_id);
        $this->assertEquals($conversation->id, $enrollment->conversation_id);
    }

    public function test_prevent_duplicate_enrollment()
    {
        $sequence = Sequence::factory()->create([
            'business_id' => $this->business->id,
            'status' => 'active',
        ]);

        SequenceStep::factory()->create([
            'sequence_id' => $sequence->id,
        ]);

        $conversation = Conversation::factory()->create([
            'business_id' => $this->business->id,
        ]);

        $this->enrollmentService->enrollConversation($sequence, $conversation);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('already has an active enrollment');

        $this->enrollmentService->enrollConversation($sequence, $conversation);
    }

    public function test_unenroll_conversation()
    {
        $sequence = Sequence::factory()->create([
            'business_id' => $this->business->id,
        ]);

        $conversation = Conversation::factory()->create([
            'business_id' => $this->business->id,
        ]);

        $enrollment = SequenceEnrollment::factory()->create([
            'sequence_id' => $sequence->id,
            'conversation_id' => $conversation->id,
            'status' => 'active',
        ]);

        $result = $this->enrollmentService->unenrollConversation($enrollment, 'manual');

        $this->assertTrue($result);
        $this->assertEquals('stopped', $enrollment->fresh()->status);
    }

    public function test_stop_enrollments_for_sequence()
    {
        $sequence = Sequence::factory()->create([
            'business_id' => $this->business->id,
        ]);

        $conversation1 = Conversation::factory()->create([
            'business_id' => $this->business->id,
        ]);

        $conversation2 = Conversation::factory()->create([
            'business_id' => $this->business->id,
        ]);

        SequenceEnrollment::factory()->create([
            'sequence_id' => $sequence->id,
            'conversation_id' => $conversation1->id,
            'status' => 'active',
        ]);

        SequenceEnrollment::factory()->create([
            'sequence_id' => $sequence->id,
            'conversation_id' => $conversation2->id,
            'status' => 'active',
        ]);

        $count = $this->enrollmentService->stopEnrollmentsForSequence($sequence, 'test');

        $this->assertEquals(2, $count);
        
        $this->assertEquals(0, $sequence->activeEnrollments()->count());
    }

    public function test_stop_enrollments_for_conversation()
    {
        $sequence1 = Sequence::factory()->create([
            'business_id' => $this->business->id,
        ]);

        $sequence2 = Sequence::factory()->create([
            'business_id' => $this->business->id,
        ]);

        $conversation = Conversation::factory()->create([
            'business_id' => $this->business->id,
        ]);

        SequenceEnrollment::factory()->create([
            'sequence_id' => $sequence1->id,
            'conversation_id' => $conversation->id,
            'status' => 'active',
        ]);

        SequenceEnrollment::factory()->create([
            'sequence_id' => $sequence2->id,
            'conversation_id' => $conversation->id,
            'status' => 'active',
        ]);

        $count = $this->enrollmentService->stopEnrollmentsForConversation($conversation, 'test');

        $this->assertEquals(2, $count);
    }

    public function test_can_enroll()
    {
        $sequence = Sequence::factory()->create([
            'business_id' => $this->business->id,
            'status' => 'active',
        ]);

        SequenceStep::factory()->create([
            'sequence_id' => $sequence->id,
        ]);

        $conversation = Conversation::factory()->create([
            'business_id' => $this->business->id,
        ]);

        $canEnroll = $this->enrollmentService->canEnroll($sequence, $conversation);

        $this->assertTrue($canEnroll);
    }

    public function test_cannot_enroll_inactive_sequence()
    {
        $sequence = Sequence::factory()->create([
            'business_id' => $this->business->id,
            'status' => 'draft',
        ]);

        $conversation = Conversation::factory()->create([
            'business_id' => $this->business->id,
        ]);

        $canEnroll = $this->enrollmentService->canEnroll($sequence, $conversation);

        $this->assertFalse($canEnroll);
    }

    public function test_get_enrollment_stats()
    {
        $sequence = Sequence::factory()->create([
            'business_id' => $this->business->id,
        ]);

        $conversation = Conversation::factory()->create([
            'business_id' => $this->business->id,
        ]);

        SequenceEnrollment::factory()->create([
            'sequence_id' => $sequence->id,
            'conversation_id' => $conversation->id,
            'status' => 'active',
        ]);

        SequenceEnrollment::factory()->create([
            'sequence_id' => $sequence->id,
            'conversation_id' => Conversation::factory()->create(['business_id' => $this->business->id])->id,
            'status' => 'completed',
        ]);

        $stats = $this->enrollmentService->getEnrollmentStatsForSequence($sequence);

        $this->assertEquals(2, $stats['total']);
        $this->assertEquals(1, $stats['active']);
        $this->assertEquals(1, $stats['completed']);
    }
}
