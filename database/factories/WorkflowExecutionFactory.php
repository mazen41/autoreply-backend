<?php

namespace Database\Factories;

use App\Models\WorkflowExecution;
use App\Models\AutomationWorkflow;
use App\Models\Conversation;
use App\Models\BusinessProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

class WorkflowExecutionFactory extends Factory
{
    protected $model = WorkflowExecution::class;

    public function definition(): array
    {
        return [
            'workflow_id' => AutomationWorkflow::factory(),
            'conversation_id' => Conversation::factory(),
            'status' => fake()->randomElement(['pending', 'running', 'completed', 'failed']),
            'trigger_data' => [
                'trigger_type' => fake()->word(),
            ],
            'results' => fake()->optional()->randomElement([
                ['triggered' => true, 'actions_executed' => []],
                ['triggered' => false],
            ]),
            'error_message' => fake()->optional()->sentence(),
            'test_mode' => fake()->boolean(20), // 20% chance of being test mode
            'started_at' => fake()->dateTime(),
            'completed_at' => fake()->optional()->dateTime(),
        ];
    }
}
