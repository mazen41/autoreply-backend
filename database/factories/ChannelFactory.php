<?php

namespace Database\Factories;

use App\Models\Channel;
use App\Models\BusinessProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

class ChannelFactory extends Factory
{
    protected $model = Channel::class;

    public function definition(): array
    {
        return [
            'user_id' => 1,
            'business_id' => BusinessProfile::factory(),
            'type' => fake()->randomElement(['whatsapp', 'telegram', 'facebook', 'instagram', 'gmail']),
            'page_id' => fake()->numerify('#########'),
            'page_name' => fake()->company(),
            'page_access_token' => fake()->sha256(),
            'webhook_secret' => fake()->sha256(),
            'is_active' => true,
        ];
    }
}