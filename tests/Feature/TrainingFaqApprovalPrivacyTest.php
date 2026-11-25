<?php

namespace Tests\Feature;

use App\Models\BusinessProfile;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageCorrection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TrainingFaqApprovalPrivacyTest extends TestCase
{
    use RefreshDatabase;

    public function test_faq_approval_requires_reviewer_question_and_stores_redacted_content(): void
    {
        $user = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $user->id]);
        $channel = Channel::factory()->create(['user_id' => $user->id, 'business_id' => $business->id]);
        $conversation = Conversation::factory()->create(['business_id' => $business->id, 'channel_id' => $channel->id]);
        $message = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'direction' => 'inbound',
            'content' => 'What is the return policy for order #1234567?',
        ]);
        $correction = MessageCorrection::create([
            'original_message_id' => $message->id,
            'ai_draft' => 'The item can be returned.',
            'human_correction' => 'Email help@example.com or call +966 50 530 8084.',
            'learning_type' => 'faq',
        ]);
        $this->actingAs($user, 'sanctum')->withHeader('X-Business-Id', (string) $business->id);

        $this->postJson("/api/training/corrections/{$correction->id}/approve")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('question');
        $this->assertFalse($correction->fresh()->approved);

        $this->postJson("/api/training/corrections/{$correction->id}/approve", [
            'question' => 'Can I return order #1234567?',
        ])->assertOk()->assertJsonPath('message', 'Added to FAQs for this business.');

        $faq = $business->fresh()->faqs[0];
        $this->assertSame('Can I return [REDACTED_ORDER_NUMBER]?', $faq['question']);
        $this->assertStringNotContainsString('help@example.com', $faq['answer']);
        $this->assertStringNotContainsString('+966 50 530 8084', $faq['answer']);
    }

    public function test_corrections_endpoint_returns_redacted_review_content(): void
    {
        $user = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $user->id]);
        $channel = Channel::factory()->create(['user_id' => $user->id, 'business_id' => $business->id]);
        $conversation = Conversation::factory()->create(['business_id' => $business->id, 'channel_id' => $channel->id]);
        $message = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'direction' => 'inbound',
            'content' => 'Call me at 01152879755.',
        ]);
        MessageCorrection::create([
            'original_message_id' => $message->id,
            'ai_draft' => 'Contact mazen@example.com.',
            'human_correction' => 'Phone +966 50 530 8084.',
            'learning_type' => 'faq',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->withHeader('X-Business-Id', (string) $business->id)
            ->getJson('/api/training/corrections')
            ->assertOk();

        $response->assertJsonPath('corrections.0.ai_draft', 'Contact [REDACTED_EMAIL].')
            ->assertJsonPath('corrections.0.human_correction', 'Phone [REDACTED_PHONE].')
            ->assertJsonPath('corrections.0.original_message.content', 'Call me at [REDACTED_PHONE].');
    }
}
