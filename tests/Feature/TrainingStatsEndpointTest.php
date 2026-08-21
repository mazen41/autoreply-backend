<?php

namespace Tests\Feature;

use App\Models\BusinessProfile;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageFeedback;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TrainingStatsEndpointTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(array $attrs = []): User
    {
        return User::factory()->create($attrs);
    }

    private function makeChannel(User $user, array $attrs = []): Channel
    {
        $attrs = array_merge([
            'type' => 'whatsapp',
            'page_id' => 'wa-1',
            'page_name' => 'WA',
            'access_token' => 'secret-token',
            'status' => 'connected',
            'connected_at' => now(),
            'ai_enabled' => true,
        ], $attrs);
        $attrs['user_id'] = $user->id;
        return Channel::create($attrs);
    }

    private function makeConversation(Channel $channel, array $attrs = []): Conversation
    {
        $attrs = array_merge([
            'channel_id' => $channel->id,
            'sender_id' => 'buyer-1',
            'sender_name' => 'Buyer',
            'status' => 'open',
            'ai_enabled' => true,
        ], $attrs);
        return Conversation::create($attrs);
    }

    private function makeAiMessage(Conversation $conversation, array $attrs = []): Message
    {
        $attrs = array_merge([
            'conversation_id' => $conversation->id,
            'content' => 'AI reply',
            'direction' => 'outbound',
            'status' => 'auto',
            'is_ai' => true,
            'source' => 'ai',
            'send_status' => 'sent',
        ], $attrs);
        return Message::create($attrs);
    }

    public function test_empty_database_returns_zeroes_and_null_rates(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $this->getJson('/api/training/stats')
            ->assertOk()
            ->assertJsonPath('total_ai_messages', 0)
            ->assertJsonPath('total_conversations', 0)
            ->assertJsonPath('feedback_total', 0)
            ->assertJsonPath('avg_confidence', null)
            ->assertJsonPath('escalation_rate', null)
            ->assertJsonPath('auto_reply_rate', null)
            ->assertJsonPath('satisfaction_percentage', null)
            ->assertJson([]);
    }

    public function test_avg_confidence_excludes_null_and_normalizes_scale(): void
    {
        $user = $this->makeUser();
        $channel = $this->makeChannel($user);
        $conv = $this->makeConversation($channel);

        $this->makeAiMessage($conv, ['confidence_score' => 0.9]);
        $this->makeAiMessage($conv, ['confidence_score' => 0.8]);
        $this->makeAiMessage($conv, ['confidence_score' => null]); // excluded
        $this->makeAiMessage($conv, ['confidence_score' => 70]);   // 0–100 → normalized to 0.7

        Sanctum::actingAs($user);

        $this->getJson('/api/training/stats')
            ->assertOk()
            ->assertJsonPath('total_ai_messages', 4)
            ->assertJsonPath('confidence_count', 3)                 // NULL excluded
            ->assertJsonPath('confidence_total', 4)
            ->assertJsonPath('avg_confidence', 80.0);               // (0.9+0.8+0.7)/3
    }

    public function test_non_ai_messages_are_excluded(): void
    {
        $user = $this->makeUser();
        $channel = $this->makeChannel($user);
        $conv = $this->makeConversation($channel);

        $this->makeAiMessage($conv, ['confidence_score' => 0.9]);
        Message::create([
            'conversation_id' => $conv->id,
            'content' => 'manual reply',
            'direction' => 'outbound',
            'status' => 'manual',
            'is_ai' => false,
            'source' => 'manual',
        ]);
        Message::create([
            'conversation_id' => $conv->id,
            'content' => 'inbound customer',
            'direction' => 'inbound',
            'status' => 'received',
            'is_ai' => false,
            'source' => 'customer',
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/training/stats')
            ->assertOk()
            ->assertJsonPath('total_ai_messages', 1);
    }

    public function test_statistics_are_isolated_per_user(): void
    {
        $userA = $this->makeUser();
        $userB = $this->makeUser();

        $channelA = $this->makeChannel($userA);
        $this->makeAiMessage($this->makeConversation($channelA), ['confidence_score' => 0.9]);

        $channelB = $this->makeChannel($userB);
        $this->makeAiMessage($this->makeConversation($channelB), ['confidence_score' => 0.2]);

        Sanctum::actingAs($userA);

        $this->getJson('/api/training/stats')
            ->assertOk()
            ->assertJsonPath('total_ai_messages', 1)
            ->assertJsonPath('avg_confidence', 90.0) // only user A's channel
            ->assertJsonPath('confidence_count', 1);
    }

    public function test_user_cannot_read_another_business_statistics(): void
    {
        $owner = $this->makeUser();
        $intruder = $this->makeUser();

        $business = BusinessProfile::create([
            'user_id' => $owner->id,
            'business_name' => 'Owner Biz',
            'business_type' => 'retail',
        ]);

        $channel = $this->makeChannel($owner, ['business_id' => $business->id]);
        $this->makeAiMessage($this->makeConversation($channel), ['confidence_score' => 0.9]);

        Sanctum::actingAs($intruder);

        // Intruder passing someone else's business_id → 404 (not exposed).
        $this->getJson('/api/training/stats?business_id=' . $business->id)
            ->assertNotFound();
    }

    public function test_escalation_statistics(): void
    {
        $user = $this->makeUser();
        $channel = $this->makeChannel($user);

        $conv1 = $this->makeConversation($channel, ['requires_human' => true, 'escalated_at' => now(), 'escalation_reason' => 'ai_hard_escalation: complaint']);
        $this->makeAiMessage($conv1);
        $conv2 = $this->makeConversation($channel, ['requires_human' => false]);
        $this->makeAiMessage($conv2);

        Sanctum::actingAs($user);

        $this->getJson('/api/training/stats')
            ->assertOk()
            ->assertJsonPath('escalated_conversations', 1)
            ->assertJsonPath('escalations_today', 1)
            ->assertJsonPath('escalation_rate', 50.0)
            ->assertJsonPath('escalation_reasons.complaint', 1);
    }

    public function test_feedback_statistics(): void
    {
        $user = $this->makeUser();
        $channel = $this->makeChannel($user);
        $conv = $this->makeConversation($channel);

        $good = $this->makeAiMessage($conv, ['confidence_score' => 0.9]);
        $bad = $this->makeAiMessage($conv, ['confidence_score' => 0.4]);

        MessageFeedback::create([
            'message_id' => $good->id, 'user_id' => $user->id,
            'feedback' => 'positive',
        ]);
        MessageFeedback::create([
            'message_id' => $bad->id, 'user_id' => $user->id,
            'feedback' => 'negative', 'issue_type' => 'inaccurate',
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/training/stats')
            ->assertOk()
            ->assertJsonPath('feedback_total', 2)
            ->assertJsonPath('feedback_positive', 1)
            ->assertJsonPath('feedback_negative', 1)
            ->assertJsonPath('satisfaction_percentage', 50.0)
            ->assertJsonPath('issue_breakdown.inaccurate', 1);
    }

    public function test_date_range_affects_query(): void
    {
        $user = $this->makeUser();
        $channel = $this->makeChannel($user);
        $conv = $this->makeConversation($channel);

        $this->makeAiMessage($conv, ['confidence_score' => 0.9]); // now
        $this->makeAiMessage($conv, [
            'confidence_score' => 0.9,
            'created_at' => Carbon::now()->subDays(40),
        ]);

        Sanctum::actingAs($user);

        // Default (last_30_days) should exclude the 40-day-old message.
        $this->getJson('/api/training/stats')
            ->assertOk()
            ->assertJsonPath('total_ai_messages', 1);

        // all_time includes it.
        $this->getJson('/api/training/stats?preset=all_time')
            ->assertOk()
            ->assertJsonPath('total_ai_messages', 2);
    }

    public function test_invalid_preset_is_rejected(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $this->getJson('/api/training/stats?preset=not-a-real-range')
            ->assertStatus(422);
    }

    public function test_dialect_breakdown_does_not_count_null_as_a_dialect(): void
    {
        $user = $this->makeUser();
        $channel = $this->makeChannel($user);
        $conv = $this->makeConversation($channel);

        $this->makeAiMessage($conv, ['detected_language' => 'arabic', 'detected_dialect' => 'egyptian']);
        $this->makeAiMessage($conv, ['detected_language' => 'english', 'detected_dialect' => null]);
        $this->makeAiMessage($conv, ['detected_language' => 'arabic', 'detected_dialect' => null]); // arabic, unclassified
        $this->makeAiMessage($conv, ['detected_language' => null, 'detected_dialect' => 'unknown']); // legacy

        Sanctum::actingAs($user);

        $this->getJson('/api/training/stats')
            ->assertOk()
            ->assertJsonPath('dialect_breakdown.egyptian', 1)
            ->assertJsonPath('dialect_breakdown.english', 1)
            ->assertJsonPath('dialect_breakdown.unknown', 2); // unclassified arabic + legacy
    }
}