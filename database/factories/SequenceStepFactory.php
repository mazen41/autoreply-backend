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
        static $stepOrders = [];

        $sequenceId = null; // will be resolved after creation

        return [
            'sequence_id' => Sequence::factory(),
            'step_order' => 1,
            'step_type' => 'message',
            'message' => fake()->sentence(),
            'config' => null,
            'delay_hours' => fake()->numberBetween(0, 48),
            'delay_unit' => fake()->randomElement(['minutes', 'hours', 'days']),
            'condition_config' => null,
            'is_active' => true,
        ];
    }
}
