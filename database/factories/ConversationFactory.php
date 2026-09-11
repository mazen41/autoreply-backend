<?php

namespace Database\Factories;

use App\Models\Conversation;
use App\Models\BusinessProfile;
use App\Models\Channel;
use Illuminate\Database\Eloquent\Factories\Factory;

class ConversationFactory extends Factory
{
    protected $model = Conversation::class;

    public function definition(): array
    {
        return [
            'business_id' => BusinessProfile::factory(),
            'channel_id' => Channel::factory(),
            'sender_id' => fake()->numerify('##########'),
            'sender_name' => fake()->name(),
            'status' => 'open',
            'ai_enabled' => true,
        ];
    }
}
