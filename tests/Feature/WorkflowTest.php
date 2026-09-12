<?php

namespace Tests\Feature;

use App\Models\AutomationWorkflow;
use App\Models\WorkflowExecution;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\BusinessProfile;
use App\Models\User;
use App\Models\TeamMember;
use App\Services\AutomationEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private BusinessProfile $business;
    private User $otherUser;
    private BusinessProfile $otherBusiness;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->business = BusinessProfile::factory()->create([
            'user_id' => $this->user->id,
        ]);

        $this->otherUser = User::factory()->create();
        $this->otherBusiness = BusinessProfile::factory()->create([
            'user_id' => $this->otherUser->id,
        ]);
    }

    public function test_user_can_create_workflow()
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/workflows', [
                'name' => 'Test Workflow',
                'description' => 'A test workflow',
                'trigger' => [
                    'type' => 'keyword',
                    'conditions' => [
                        'keywords' => ['price'],
                        'match_type' => 'any',
                    ],
                ],
                'actions' => [
                    [
                        'type' => 'add_tag',
                        'tag' => 'price_inquiry',
                    ],
                ],
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('automation_workflows', [
            'name' => 'Test Workflow',
            'business_id' => $this->business->id,
        ]);
    }

    public function test_user_can_list_their_workflows()
    {
        AutomationWorkflow::factory()->create([
            'user_id' => $this->user->id,
            'business_id' => $this->business->id,
            'name' => 'My Workflow',
        ]);

        AutomationWorkflow::factory()->create([
            'user_id' => $this->otherUser->id,
            'business_id' => $this->otherBusiness->id,
            'name' => 'Other Workflow',
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/workflows');

        $response->assertStatus(200);
        $workflows = $response->json();
        $this->assertCount(1, $workflows);
        $this->assertEquals('My Workflow', $workflows[0]['name']);
    }

    public function test_user_can_update_their_workflow()
    {
        $workflow = AutomationWorkflow::factory()->create([
            'user_id' => $this->user->id,
            'business_id' => $this->business->id,
            'name' => 'Old Name',
        ]);

        $response = $this->actingAs($this->user)
            ->patchJson("/api/workflows/{$workflow->id}", [
                'name' => 'New Name',
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('automation_workflows', [
            'id' => $workflow->id,
            'name' => 'New Name',
        ]);
    }

    public function test_user_cannot_update_other_business_workflow()
    {
        $workflow = AutomationWorkflow::factory()->create([
            'user_id' => $this->otherUser->id,
            'business_id' => $this->otherBusiness->id,
        ]);

        $response = $this->actingAs($this->user)
            ->patchJson("/api/workflows/{$workflow->id}", [
                'name' => 'Hacked Name',
            ]);

        $response->assertStatus(404);
    }

    public function test_user_can_delete_their_workflow()
    {
        $workflow = AutomationWorkflow::factory()->create([
            'user_id' => $this->user->id,
            'business_id' => $this->business->id,
        ]);

        $response = $this->actingAs($this->user)
            ->deleteJson("/api/workflows/{$workflow->id}");

        $response->assertStatus(200);
        $this->assertDatabaseMissing('automation_workflows', [
            'id' => $workflow->id,
        ]);
    }

    public function test_user_can_toggle_workflow()
    {
        $workflow = AutomationWorkflow::factory()->create([
            'user_id' => $this->user->id,
            'business_id' => $this->business->id,
            'active' => true,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/workflows/{$workflow->id}/toggle");

        $response->assertStatus(200);
        $this->assertDatabaseHas('automation_workflows', [
            'id' => $workflow->id,
            'active' => false,
        ]);
    }

    public function test_user_can_duplicate_workflow()
    {
        $workflow = AutomationWorkflow::factory()->create([
            'user_id' => $this->user->id,
            'business_id' => $this->business->id,
            'name' => 'Original',
            'executions_count' => 5,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/workflows/{$workflow->id}/duplicate");

        $response->assertStatus(201);
        $this->assertDatabaseHas('automation_workflows', [
            'name' => 'Original (Copy)',
            'executions_count' => 0,
        ]);
    }

    public function test_team_member_can_access_business_workflows()
    {
        $teamMember = TeamMember::factory()->create([
            'business_id' => $this->business->id,
            'user_id' => $this->otherUser->id,
            'role' => 'agent',
            'is_active' => true,
        ]);

        $workflow = AutomationWorkflow::factory()->create([
            'user_id' => $this->user->id,
            'business_id' => $this->business->id,
        ]);

        $response = $this->actingAs($this->otherUser)
            ->getJson('/api/workflows');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json());
    }

    public function test_inactive_team_member_cannot_access_workflows()
    {
        TeamMember::factory()->create([
            'business_id' => $this->business->id,
            'user_id' => $this->otherUser->id,
            'role' => 'agent',
            'is_active' => false,
        ]);

        AutomationWorkflow::factory()->create([
            'user_id' => $this->user->id,
            'business_id' => $this->business->id,
        ]);

        $response = $this->actingAs($this->otherUser)
            ->getJson('/api/workflows');

        $response->assertStatus(200);
        $this->assertCount(0, $response->json());
    }

    public function test_workflow_execution_creates_record()
    {
        $workflow = AutomationWorkflow::factory()->create([
            'user_id' => $this->user->id,
            'business_id' => $this->business->id,
            'executions_count' => 0,
            'trigger_config' => [
                'type' => 'keyword',
                'conditions' => [
                    'keywords' => ['price'],
                    'match_type' => 'any',
                ],
            ],
            'actions_config' => [
                [
                    'type' => 'add_tag',
                    'tag' => 'price_inquiry',
                ],
            ],
        ]);

        $conversation = Conversation::factory()->create([
            'business_id' => $this->business->id,
        ]);

        Message::factory()->create([
            'conversation_id' => $conversation->id,
            'direction' => 'inbound',
            'content' => 'What is the price?',
        ]);

        $engine = app(AutomationEngine::class);
        $engine->executeWorkflow($workflow, $conversation, testMode: false);

        $this->assertDatabaseHas('workflow_executions', [
            'workflow_id' => $workflow->id,
            'conversation_id' => $conversation->id,
            'status' => 'completed',
        ]);

        $this->assertDatabaseHas('automation_workflows', [
            'id' => $workflow->id,
            'executions_count' => 1,
        ]);
    }

    public function test_workflow_test_mode_does_not_increment_count()
    {
        $workflow = AutomationWorkflow::factory()->create([
            'user_id' => $this->user->id,
            'business_id' => $this->business->id,
            'trigger_config' => [
                'type' => 'keyword',
                'conditions' => [
                    'keywords' => ['price'],
                    'match_type' => 'any',
                ],
            ],
            'actions_config' => [
                [
                    'type' => 'add_tag',
                    'tag' => 'price_inquiry',
                ],
            ],
            'executions_count' => 0,
        ]);

        $conversation = Conversation::factory()->create([
            'business_id' => $this->business->id,
        ]);

        Message::factory()->create([
            'conversation_id' => $conversation->id,
            'direction' => 'inbound',
            'content' => 'What is the price?',
        ]);

        $engine = app(AutomationEngine::class);
        $engine->executeWorkflow($workflow, $conversation, testMode: true);

        $this->assertDatabaseHas('automation_workflows', [
            'id' => $workflow->id,
            'executions_count' => 0,
        ]);

        $this->assertDatabaseHas('workflow_executions', [
            'workflow_id' => $workflow->id,
            'test_mode' => true,
        ]);
    }

    public function test_workflow_non_matching_trigger_does_not_execute()
    {
        $workflow = AutomationWorkflow::factory()->create([
            'user_id' => $this->user->id,
            'business_id' => $this->business->id,
            'trigger_config' => [
                'type' => 'keyword',
                'conditions' => [
                    'keywords' => ['price'],
                    'match_type' => 'any',
                ],
            ],
            'actions_config' => [
                [
                    'type' => 'add_tag',
                    'tag' => 'price_inquiry',
                ],
            ],
        ]);

        $conversation = Conversation::factory()->create([
            'business_id' => $this->business->id,
        ]);

        Message::factory()->create([
            'conversation_id' => $conversation->id,
            'direction' => 'inbound',
            'content' => 'Hello there',
        ]);

        $engine = app(AutomationEngine::class);
        $results = $engine->executeWorkflow($workflow, $conversation, testMode: false);

        $this->assertFalse($results['triggered']);
        $this->assertDatabaseHas('workflow_executions', [
            'workflow_id' => $workflow->id,
            'status' => 'completed',
        ]);
    }

    public function test_get_workflow_executions()
    {
        $workflow = AutomationWorkflow::factory()->create([
            'user_id' => $this->user->id,
            'business_id' => $this->business->id,
        ]);

        WorkflowExecution::factory()->create([
            'workflow_id' => $workflow->id,
            'status' => 'completed',
        ]);

        WorkflowExecution::factory()->create([
            'workflow_id' => $workflow->id,
            'status' => 'failed',
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/workflows/{$workflow->id}/executions");

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(2, $data);
    }

    public function test_get_workflow_stats()
    {
        $workflow = AutomationWorkflow::factory()->create([
            'user_id' => $this->user->id,
            'business_id' => $this->business->id,
            'executions_count' => 10,
        ]);

        WorkflowExecution::factory()->count(7)->create([
            'workflow_id' => $workflow->id,
            'status' => 'completed',
        ]);

        WorkflowExecution::factory()->count(3)->create([
            'workflow_id' => $workflow->id,
            'status' => 'failed',
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/workflows/{$workflow->id}/stats");

        $response->assertStatus(200);
        $stats = $response->json();
        $this->assertEquals(10, $stats['total_executions']);
        $this->assertEquals(7, $stats['successful_executions']);
        $this->assertEquals(3, $stats['failed_executions']);
    }

    public function test_test_endpoint_executes_workflow_in_test_mode()
    {
        $workflow = AutomationWorkflow::factory()->create([
            'user_id' => $this->user->id,
            'business_id' => $this->business->id,
            'trigger_config' => [
                'type' => 'keyword',
                'conditions' => [
                    'keywords' => ['price'],
                    'match_type' => 'any',
                ],
            ],
            'actions_config' => [
                [
                    'type' => 'add_tag',
                    'tag' => 'price_inquiry',
                ],
            ],
        ]);

        $conversation = Conversation::factory()->create([
            'business_id' => $this->business->id,
        ]);

        Message::factory()->create([
            'conversation_id' => $conversation->id,
            'direction' => 'inbound',
            'content' => 'What is the price?',
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/workflows/{$workflow->id}/test", [
                'conversation_id' => $conversation->id,
            ]);

        $response->assertStatus(200);
        $results = $response->json();
        $this->assertTrue($results['triggered']);
    }
}
