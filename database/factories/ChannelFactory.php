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
            'user_id' => null,
            'business_id' => BusinessProfile::factory(),
            'type' => fake()->randomElement(['whatsapp', 'telegram', 'facebook', 'instagram', 'gmail']),
            'page_id' => fake()->numerify('#########'),
            'page_name' => fake()->company(),
            'instagram_account_id' => null,
            'access_token' => fake()->sha256(),
            'refresh_token' => null,
            'status' => 'connected',
            'connected_at' => now(),
        ];
    }
}
