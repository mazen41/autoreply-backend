<?php

namespace Tests\Feature;

use App\Models\Sequence;
use App\Models\SequenceStep;
use App\Models\SequenceEnrollment;
use App\Models\Conversation;
use App\Models\BusinessProfile;
use App\Models\User;
use App\Services\SequenceService;
use App\Services\SequenceEnrollmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SequenceServiceTest extends TestCase
{
    use RefreshDatabase;

    private SequenceService $sequenceService;
    private SequenceEnrollmentService $enrollmentService;
    private User $user;
    private BusinessProfile $business;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->sequenceService = new SequenceService();
        $this->enrollmentService = new SequenceEnrollmentService();
        
        $this->user = User::factory()->create();
        $this->business = BusinessProfile::factory()->create();
        $this->user->business_id = $this->business->id;
        $this->user->save();
    }

    public function test_create_sequence()
    {
        $data = [
            'name' => 'Test Sequence',
            'description' => 'Test description',
            'trigger_type' => 'manual',
            'channel' => 'whatsapp',
            'steps' => [
                [
                    'step_type' => 'message',
                    'message' => 'Hello',
                    'delay_hours' => 0,
                ],
            ],
        ];

        $sequence = $this->sequenceService->createSequence($data, $this->business->id);

        $this->assertInstanceOf(Sequence::class, $sequence);
        $this->assertEquals('Test Sequence', $sequence->name);
        $this->assertEquals('draft', $sequence->status);
        $this->assertEquals($this->business->id, $sequence->business_id);
        $this->assertCount(1, $sequence->steps);
    }

    public function test_update_sequence()
    {
        $sequence = Sequence::factory()->create([
            'business_id' => $this->business->id,
            'name' => 'Original Name',
        ]);

        $data = [
            'name' => 'Updated Name',
            'description' => 'Updated description',
            'steps' => [
                [
                    'step_type' => 'message',
                    'message' => 'Updated message',
                    'delay_hours' => 1,
                ],
            ],
        ];

        $updated = $this->sequenceService->updateSequence($sequence, $data);

        $this->assertEquals('Updated Name', $updated->name);
        $this->assertEquals('Updated description', $updated->description);
        $this->assertCount(1, $updated->steps);
    }

    public function test_delete_sequence()
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

        $result = $this->sequenceService->deleteSequence($sequence);

        $this->assertTrue($result);
        $this->assertDatabaseMissing('sequences', ['id' => $sequence->id]);
        
        // Check enrollment was stopped
        $this->assertDatabaseHas('sequence_users', [
            'id' => $enrollment->id,
            'status' => 'stopped',
        ]);
    }

    public function test_duplicate_sequence()
    {
        $sequence = Sequence::factory()->create([
            'business_id' => $this->business->id,
            'name' => 'Original Sequence',
        ]);

        SequenceStep::factory()->create([
            'sequence_id' => $sequence->id,
            'step_order' => 1,
        ]);

        $duplicated = $this->sequenceService->duplicateSequence($sequence);

        $this->assertNotEquals($sequence->id, $duplicated->id);
        $this->assertEquals('Original Sequence (Copy)', $duplicated->name);
        $this->assertEquals('draft', $duplicated->status);
        $this->assertCount(1, $duplicated->steps);
    }

    public function test_activate_sequence()
    {
        $sequence = Sequence::factory()->create([
            'business_id' => $this->business->id,
            'status' => 'draft',
        ]);

        SequenceStep::factory()->create([
            'sequence_id' => $sequence->id,
        ]);

        $activated = $this->sequenceService->activateSequence($sequence);

        $this->assertEquals('active', $activated->status);
    }

    public function test_activate_sequence_without_steps_fails()
    {
        $sequence = Sequence::factory()->create([
            'business_id' => $this->business->id,
            'status' => 'draft',
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Sequence must have at least one step');

        $this->sequenceService->activateSequence($sequence);
    }

    public function test_pause_sequence()
    {
        $sequence = Sequence::factory()->create([
            'business_id' => $this->business->id,
            'status' => 'active',
        ]);

        $paused = $this->sequenceService->pauseSequence($sequence);

        $this->assertEquals('paused', $paused->status);
    }

    public function test_archive_sequence()
    {
        $sequence = Sequence::factory()->create([
            'business_id' => $this->business->id,
            'status' => 'active',
        ]);

        $archived = $this->sequenceService->archiveSequence($sequence);

        $this->assertEquals('archived', $archived->status);
    }

    public function test_get_sequences_for_business()
    {
        Sequence::factory()->count(3)->create([
            'business_id' => $this->business->id,
            'status' => 'active',
        ]);

        Sequence::factory()->create([
            'business_id' => $this->business->id,
            'status' => 'draft',
        ]);

        $sequences = $this->sequenceService->getSequencesForBusiness($this->business->id, [
            'status' => 'active',
        ]);

        $this->assertCount(3, $sequences);
    }

    public function test_sequence_validation()
    {
        $sequence = Sequence::factory()->create([
            'business_id' => $this->business->id,
            'name' => '',
        ]);

        $errors = $this->sequenceService->validateSequence($sequence);

        $this->assertContains('Sequence name is required', $errors);
    }

    public function test_business_isolation()
    {
        $otherBusiness = BusinessProfile::factory()->create();
        
        $sequence = Sequence::factory()->create([
            'business_id' => $this->business->id,
        ]);

        $sequences = $this->sequenceService->getSequencesForBusiness($otherBusiness->id);

        $this->assertCount(0, $sequences);
    }
}
