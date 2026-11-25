<?php

namespace Tests\Feature;

use App\Models\BusinessProfile;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageCorrection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TrainingCorrectionApprovalResponseTest extends TestCase
{
    use RefreshDatabase;

    public function test_approval_response_explains_the_effect_for_each_correction_type(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $user->id]);
        $channel = Channel::factory()->create([
            'user_id' => $user->id,
            'business_id' => $business->id,
            'type' => 'facebook',
        ]);
        $conversation = Conversation::factory()->create([
            'business_id' => $business->id,
            'channel_id' => $channel->id,
        ]);
        $message = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'direction' => 'inbound',
            'content' => 'What is your return policy?',
        ]);
        $responses = [
            'faq' => 'Added to FAQs for this business.',
            'knowledge' => 'Saved as a knowledge file and indexing started.',
            'tone' => 'Recorded for review. It does not change replies yet.',
            'general' => 'Recorded for review. It does not change replies yet.',
        ];

        foreach ($responses as $type => $expectedMessage) {
            $correction = MessageCorrection::create([
                'original_message_id' => $message->id,
                'ai_draft' => 'An AI response.',
                'human_correction' => 'A reviewed response.',
                'learning_type' => $type,
            ]);

            $this->actingAs($user, 'sanctum')
                ->withHeader('X-Business-Id', (string) $business->id)
                ->postJson("/api/training/corrections/{$correction->id}/approve", $type === 'faq'
                    ? ['question' => 'What is the return policy?']
                    : [])
                ->assertOk()
                ->assertJsonPath('message', $expectedMessage);
        }

        $this->assertDatabaseCount('business_knowledge_files', 1);
    }
}
