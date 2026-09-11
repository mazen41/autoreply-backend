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
        $business = BusinessProfile::factory()->create();

        return [
            'business_id' => $business->id,
            'channel_id' => Channel::factory()->create(['business_id' => $business->id])->id,
            'sender_id' => fake()->numerify('##########'),
            'sender_name' => fake()->name(),
            'status' => 'open',
            'ai_enabled' => true,
        ];
    }
}
