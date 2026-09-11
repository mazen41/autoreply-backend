<?php

namespace Database\Factories;

use App\Models\Message;
use App\Models\Conversation;
use Illuminate\Database\Eloquent\Factories\Factory;

class MessageFactory extends Factory
{
    protected $model = Message::class;

    public function definition(): array
    {
        return [
            'conversation_id' => Conversation::factory(),
            'content' => fake()->sentence(),
            'direction' => fake()->randomElement(['inbound', 'outbound']),
            'status' => 'sent',
            'is_ai' => fake()->boolean(),
            'source' => fake()->randomElement(['web', 'facebook', 'instagram', 'whatsapp', 'telegram']),
            'send_status' => 'sent',
        ];
    }
}
