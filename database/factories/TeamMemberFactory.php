<?php

namespace Database\Factories;

use App\Models\TeamMember;
use App\Models\BusinessProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class TeamMemberFactory extends Factory
{
    protected $model = TeamMember::class;

    public function definition(): array
    {
        return [
            'business_id' => BusinessProfile::factory(),
            'user_id' => User::factory(),
            'role' => fake()->randomElement(['owner', 'agent', 'viewer']),
            'is_active' => true,
            'invited_at' => fake()->dateTime(),
            'joined_at' => fake()->optional()->dateTime(),
        ];
    }
}
