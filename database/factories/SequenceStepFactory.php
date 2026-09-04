<?php

namespace Database\Factories;

use App\Models\SequenceStep;
use App\Models\Sequence;
use Illuminate\Database\Eloquent\Factories\Factory;

class SequenceStepFactory extends Factory
{
    protected $model = SequenceStep::class;

    public function definition(): array
    {
        return [
            'sequence_id' => Sequence::factory(),
            'step_order' => fake()->numberBetween(1, 10),
            'step_type' => fake()->randomElement(['message', 'delay', 'condition', 'action']),
            'message' => fake()->optional()->sentence(),
            'config' => fake()->optional()->json(),
            'delay_hours' => fake()->numberBetween(0, 48),
            'delay_unit' => fake()->randomElement(['minutes', 'hours', 'days']),
            'condition_config' => fake()->optional()->json(),
            'is_active' => true,
        ];
    }
}
