<?php

namespace Database\Factories;

use App\Models\AutomationWorkflow;
use App\Models\User;
use App\Models\BusinessProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

class AutomationWorkflowFactory extends Factory
{
    protected $model = AutomationWorkflow::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'business_id' => BusinessProfile::factory(),
            'name' => fake()->sentence(3),
            'description' => fake()->optional()->sentence(),
            'active' => true,
            'trigger_config' => [
                'type' => 'keyword',
                'conditions' => [
                    'keywords' => [fake()->word()],
                    'match_type' => 'any',
                ],
            ],
            'conditions' => null,
            'actions_config' => [
                [
                    'type' => 'add_tag',
                    'tag' => fake()->word(),
                ],
            ],
            'executions_count' => fake()->numberBetween(0, 100),
            'last_executed_at' => fake()->optional()->dateTime(),
        ];
    }
}
