<?php

namespace Database\Factories;

use App\Models\SequenceStepExecution;
use App\Models\Sequence;
use App\Models\SequenceEnrollment;
use App\Models\SequenceStep;
use Illuminate\Database\Eloquent\Factories\Factory;

class SequenceStepExecutionFactory extends Factory
{
    protected $model = SequenceStepExecution::class;

    public function definition(): array
    {
        return [
            'sequence_id' => Sequence::factory(),
            'sequence_enrollment_id' => SequenceEnrollment::factory(),
            'sequence_step_id' => SequenceStep::factory(),
            'status' => fake()->randomElement(['pending', 'processing', 'executed', 'failed', 'skipped']),
            'scheduled_at' => fake()->optional()->dateTimeThisMonth(),
            'executed_at' => fake()->optional()->dateTimeThisMonth(),
            'message_id' => null,
            'error' => null,
            'metadata' => null,
        ];
    }
}
