<?php

namespace Database\Factories;

use App\Models\SequenceEnrollment;
use App\Models\Sequence;
use App\Models\Conversation;
use Illuminate\Database\Eloquent\Factories\Factory;

class SequenceEnrollmentFactory extends Factory
{
    protected $model = SequenceEnrollment::class;

    public function definition(): array
    {
        return [
            'sequence_id' => Sequence::factory(),
            'conversation_id' => Conversation::factory(),
            'current_step' => fake()->numberBetween(0, 5),
            'status' => fake()->randomElement(['active', 'completed', 'stopped', 'failed']),
            'started_at' => fake()->optional()->dateTimeThisMonth(),
            'completed_at' => fake()->optional()->dateTimeThisMonth(),
            'stopped_at' => fake()->optional()->dateTimeThisMonth(),
            'next_execution_at' => fake()->optional()->dateTimeThisMonth(),
            'metadata' => fake()->optional()->json(),
        ];
    }
}
