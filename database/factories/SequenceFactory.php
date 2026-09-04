<?php

namespace Database\Factories;

use App\Models\Sequence;
use App\Models\BusinessProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

class SequenceFactory extends Factory
{
    protected $model = Sequence::class;

    public function definition(): array
    {
        return [
            'business_id' => BusinessProfile::factory(),
            'name' => fake()->sentence(3),
            'description' => fake()->optional()->sentence(),
            'trigger_type' => fake()->randomElement(['new_user', 'tag_added', 'no_reply', 'manual']),
            'trigger_config' => fake()->optional()->json(),
            'channel' => fake()->optional()->randomElement(['whatsapp', 'instagram', 'messenger', 'email', 'telegram', 'sms']),
            'status' => fake()->randomElement(['draft', 'active', 'paused', 'archived']),
            'settings' => fake()->optional()->json(),
        ];
    }
}
