<?php

namespace Tests\Feature;

use App\Models\Bot;
use App\Models\BusinessProfile;
use App\Models\Channel;
use App\Models\SocialCommentEvent;
use App\Models\User;
use App\Jobs\ProcessSocialCommentEvent;
use App\Services\MetaCommentAutomationService;
use App\Services\SocialCommentRuleEvaluator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Regression tests verifying that explicitly configured comment rules
 * take precedence over the generic-comment heuristic filter.
 *
 * Covers the fix for: short keyword comments like "Hi" being skipped
 * by isGenericComment() before rule evaluation could match them.
 */
class CommentRuleOverridesGenericFilterTest extends TestCase
{
    use RefreshDatabase;

    // ---------------------------------------------------------------
    //  1. "Hi" with an enabled exact-match Greeting rule matches
    //     and sends exactly one mocked public reply.
    // ---------------------------------------------------------------
    public function test_hi_with_exact_match_greeting_rule_resolves_bot_matches_and_sends_one_public_reply(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['id' => 'reply-hi-greeting'], 200)]);
        [$event, $bot] = $this->buildGreetingScenario('Hi');

        (new ProcessSocialCommentEvent($event->id))->handle(app(MetaCommentAutomationService::class));

        $fresh = $event->fresh();
        $this->assertSame('processed', $fresh->status);
        $this->assertSame($bot->id, $fresh->bot_id);
        $this->assertSame('reply-hi-greeting', $fresh->public_reply_id);
        $this->assertSame('HI Welcome How we can You', data_get($fresh->payload, 'decision_trace.selected_public_reply_text'));
        $this->assertSame('greeting-rule', data_get($fresh->payload, 'decision_trace.rule.id'));
        $this->assertTrue(data_get($fresh->payload, 'decision_trace.matched'));
        Http::assertSentCount(1);
        $sentRequest = Http::recorded()->first()[0];
        $this->assertSame('HI Welcome How we can You', $sentRequest->data()['message']);
    }

    // ---------------------------------------------------------------
    //  2. "HI" matches "Hi" keyword with case-insensitive exact match.
    // ---------------------------------------------------------------
    public function test_uppercase_hi_matches_greeting_rule_case_insensitively(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['id' => 'reply-HI'], 200)]);
        [$event] = $this->buildGreetingScenario('HI');

        (new ProcessSocialCommentEvent($event->id))->handle(app(MetaCommentAutomationService::class));

        $fresh = $event->fresh();
        $this->assertSame('processed', $fresh->status);
        $this->assertSame('greeting-rule', data_get($fresh->payload, 'decision_trace.rule.id'));
        Http::assertSentCount(1);
    }

    // ---------------------------------------------------------------
    //  3. "Hello" does not match exact "Hi" rule and sends no reply.
    // ---------------------------------------------------------------
    public function test_hello_does_not_match_exact_hi_rule_and_sends_nothing(): void
    {
        Http::fake();
        [$event] = $this->buildGreetingScenario('Hello');

        (new ProcessSocialCommentEvent($event->id))->handle(app(MetaCommentAutomationService::class));

        $fresh = $event->fresh();
        $this->assertSame('skipped', $fresh->status);
        $this->assertSame('no_matching_rule', $fresh->failure_code);
        $this->assertFalse(data_get($fresh->payload, 'decision_trace.matched'));
        Http::assertNothingSent();
    }

    // ---------------------------------------------------------------
    //  4. "Hi" with no matching rule does not receive an unsolicited
    //     rule-based reply (no rules configured at all).
    // ---------------------------------------------------------------
    public function test_hi_with_no_rules_configured_does_not_send_reply(): void
    {
        Http::fake();
        $user = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $user->id]);
        $bot = Bot::create(['business_profile_id' => $business->id, 'name' => 'Empty rules bot', 'status' => 'active']);
        $channel = Channel::factory()->create([
            'user_id' => $user->id, 'business_id' => $business->id, 'type' => 'facebook',
            'page_id' => 'no-rules-page', 'access_token' => 'test-token', 'status' => 'connected',
        ]);
        $bot->channels()->attach($channel->id, [
            'is_primary' => true, 'comment_automation_enabled' => true, 'public_comment_reply_enabled' => true,
            'comment_settings' => json_encode(['rules' => []]),
        ]);
        $event = $this->commentEvent($channel, $business, 'facebook', 'no-rules-hi', 'Hi');

        (new ProcessSocialCommentEvent($event->id))->handle(app(MetaCommentAutomationService::class));

        $fresh = $event->fresh();
        $this->assertSame('skipped', $fresh->status);
        // "Hi" has no matching rule AND is generic (< 3 chars), so generic_comment takes precedence
        $this->assertSame('generic_comment', $fresh->failure_code);
        Http::assertNothingSent();
    }

    // ---------------------------------------------------------------
    //  5. A disabled rule does not send a reply.
    // ---------------------------------------------------------------
    public function test_disabled_rule_does_not_match_or_send_reply(): void
    {
        Http::fake();
        $user = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $user->id]);
        $bot = Bot::create(['business_profile_id' => $business->id, 'name' => 'Disabled rule bot', 'status' => 'active']);
        $channel = Channel::factory()->create([
            'user_id' => $user->id, 'business_id' => $business->id, 'type' => 'facebook',
            'page_id' => 'disabled-rule-page', 'access_token' => 'test-token', 'status' => 'connected',
        ]);
        $bot->channels()->attach($channel->id, [
            'is_primary' => true, 'comment_automation_enabled' => true, 'public_comment_reply_enabled' => true,
            'comment_settings' => json_encode(['rules' => [[
                'id' => 'disabled-greeting', 'name' => 'Disabled Greeting', 'keywords' => ['Hi'],
                'excluded_keywords' => [], 'match' => 'equals', 'action' => 'public',
                'public_reply_variants' => ['Should not send'], 'delay_minutes' => 0,
                'post_ids' => [], 'enabled' => false,
            ]]]),
        ]);
        $event = $this->commentEvent($channel, $business, 'facebook', 'disabled-hi', 'Hi');

        (new ProcessSocialCommentEvent($event->id))->handle(app(MetaCommentAutomationService::class));

        $this->assertSame('skipped', $event->fresh()->status);
        Http::assertNothingSent();
    }

    // ---------------------------------------------------------------
    //  6. An explicit rule matches short text without being rejected
    //     by the generic-comment filter.
    // ---------------------------------------------------------------
    public function test_short_keyword_ok_matches_rule_despite_being_under_three_characters(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['id' => 'reply-ok'], 200)]);
        $user = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $user->id]);
        $bot = Bot::create(['business_profile_id' => $business->id, 'name' => 'Short keyword bot', 'status' => 'active']);
        $channel = Channel::factory()->create([
            'user_id' => $user->id, 'business_id' => $business->id, 'type' => 'facebook',
            'page_id' => 'short-keyword-page', 'access_token' => 'test-token', 'status' => 'connected',
        ]);
        $bot->channels()->attach($channel->id, [
            'is_primary' => true, 'comment_automation_enabled' => true, 'public_comment_reply_enabled' => true,
            'comment_settings' => json_encode(['rules' => [[
                'id' => 'ok-rule', 'name' => 'OK rule', 'keywords' => ['Ok'],
                'excluded_keywords' => [], 'match' => 'equals', 'action' => 'public',
                'public_reply_variants' => ['Thanks!'], 'delay_minutes' => 0,
                'post_ids' => [], 'enabled' => true,
            ]]]),
        ]);
        $event = $this->commentEvent($channel, $business, 'facebook', 'short-ok', 'Ok');

        (new ProcessSocialCommentEvent($event->id))->handle(app(MetaCommentAutomationService::class));

        $this->assertSame('processed', $event->fresh()->status);
        Http::assertSentCount(1);
    }

    // ---------------------------------------------------------------
    //  7. A comment authored by the Page itself is still ignored,
    //     even if a rule would match.
    // ---------------------------------------------------------------
    public function test_self_comment_is_skipped_even_when_rule_matches(): void
    {
        Http::fake();
        [$event] = $this->buildGreetingScenario('Hi');
        // Make commenter the same as the page (self-comment)
        $event->update(['commenter_id' => $event->account_id]);

        (new ProcessSocialCommentEvent($event->id))->handle(app(MetaCommentAutomationService::class));

        $fresh = $event->fresh();
        $this->assertSame('skipped', $fresh->status);
        $this->assertSame('self_comment', $fresh->failure_code);
        Http::assertNothingSent();
    }

    // ---------------------------------------------------------------
    //  8. No eligible assigned bot receives a specific diagnostic.
    // ---------------------------------------------------------------
    public function test_no_assigned_bot_records_correct_diagnostic_and_sends_nothing(): void
    {
        Http::fake();
        $user = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $user->id]);
        $channel = Channel::factory()->create([
            'user_id' => $user->id, 'business_id' => $business->id, 'type' => 'facebook',
            'page_id' => 'no-bot-page', 'access_token' => 'test-token', 'status' => 'connected',
        ]);
        // No bot attached to channel at all
        $event = $this->commentEvent($channel, $business, 'facebook', 'no-bot-hi', 'Hi');

        (new ProcessSocialCommentEvent($event->id))->handle(app(MetaCommentAutomationService::class));

        $fresh = $event->fresh();
        $this->assertSame('skipped', $fresh->status);
        $this->assertSame('no_unambiguous_active_bot', $fresh->failure_code);
        $this->assertNull($fresh->bot_id);
        Http::assertNothingSent();
    }

    // ---------------------------------------------------------------
    //  9. Matched rule with public replies disabled sends nothing.
    // ---------------------------------------------------------------
    public function test_matched_rule_with_public_replies_disabled_sends_nothing(): void
    {
        Http::fake();
        $user = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $user->id]);
        $bot = Bot::create(['business_profile_id' => $business->id, 'name' => 'No public bot', 'status' => 'active']);
        $channel = Channel::factory()->create([
            'user_id' => $user->id, 'business_id' => $business->id, 'type' => 'facebook',
            'page_id' => 'no-public-page', 'access_token' => 'test-token', 'status' => 'connected',
        ]);
        $bot->channels()->attach($channel->id, [
            'is_primary' => true, 'comment_automation_enabled' => true,
            'public_comment_reply_enabled' => false,  // Public replies disabled
            'comment_settings' => json_encode(['rules' => [[
                'id' => 'greeting-rule', 'name' => 'Greeting', 'keywords' => ['Hi'],
                'excluded_keywords' => [], 'match' => 'equals', 'action' => 'public',
                'public_reply_variants' => ['Hello!'], 'delay_minutes' => 0,
                'post_ids' => [], 'enabled' => true,
            ]]]),
        ]);
        $event = $this->commentEvent($channel, $business, 'facebook', 'no-public-hi', 'Hi');

        (new ProcessSocialCommentEvent($event->id))->handle(app(MetaCommentAutomationService::class));

        $fresh = $event->fresh();
        $this->assertSame('skipped', $fresh->status);
        $this->assertSame('public_reply_disabled', $fresh->failure_code);
        $this->assertTrue(data_get($fresh->payload, 'decision_trace.matched'));
        Http::assertNothingSent();
    }

    // ---------------------------------------------------------------
    //  10. A failed Meta response records failure accurately.
    // ---------------------------------------------------------------
    public function test_failed_meta_response_records_failure_status(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Permission denied']], 403)]);
        [$event] = $this->buildGreetingScenario('Hi');

        (new ProcessSocialCommentEvent($event->id))->handle(app(MetaCommentAutomationService::class));

        $fresh = $event->fresh();
        $this->assertSame('failed', $fresh->status);
        $this->assertNotNull($fresh->failure_code);
        $this->assertNull($fresh->public_reply_id);
    }

    // ---------------------------------------------------------------
    //  11. Duplicate webhook delivery does not produce duplicate replies.
    // ---------------------------------------------------------------
    public function test_duplicate_delivery_does_not_produce_duplicate_replies(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['id' => 'reply-dedup'], 200)]);
        [$event] = $this->buildGreetingScenario('Hi');

        // Process the same event twice
        (new ProcessSocialCommentEvent($event->id))->handle(app(MetaCommentAutomationService::class));
        (new ProcessSocialCommentEvent($event->id))->handle(app(MetaCommentAutomationService::class));

        $this->assertSame('processed', $event->fresh()->status);
        // First call sends the reply; second call finds terminal status and returns early
        Http::assertSentCount(1);
    }

    // ---------------------------------------------------------------
    //  12. Simulator and live rule evaluation agree for the same input.
    // ---------------------------------------------------------------
    public function test_simulator_and_live_evaluation_agree_for_hi(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['id' => 'reply-parity'], 200)]);
        $user = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $user->id]);
        $user->forceFill(['business_id' => $business->id])->save();
        $bot = Bot::create(['business_profile_id' => $business->id, 'name' => 'Parity bot', 'status' => 'active']);
        $channel = Channel::factory()->create([
            'user_id' => $user->id, 'business_id' => $business->id, 'type' => 'facebook',
            'page_id' => 'parity-page', 'access_token' => 'parity-token', 'status' => 'connected',
        ]);
        $bot->channels()->attach($channel->id, [
            'is_primary' => true, 'comment_automation_enabled' => true, 'public_comment_reply_enabled' => true,
            'comment_settings' => json_encode(['rules' => [[
                'id' => 'greeting-rule', 'name' => 'Greeting', 'keywords' => ['Hi'],
                'excluded_keywords' => [], 'match' => 'equals', 'action' => 'public',
                'public_reply_variants' => ['HI Welcome How we can You'], 'delay_minutes' => 0,
                'post_ids' => [], 'enabled' => true,
            ]]]),
        ]);
        Sanctum::actingAs($user);

        // Simulator dry-run
        $simResponse = $this->withHeader('X-Business-Id', (string) $business->id)->postJson('/api/social-comments/simulate', [
            'bot_id' => $bot->id, 'channel_id' => $channel->id, 'comment_text' => 'Hi',
        ]);
        $simResponse->assertOk()
            ->assertJsonPath('dry_run', true)
            ->assertJsonPath('decision.matched', true)
            ->assertJsonPath('decision.rule.id', 'greeting-rule');

        // Live processing
        $event = $this->commentEvent($channel, $business, 'facebook', 'parity-hi', 'Hi');
        (new ProcessSocialCommentEvent($event->id))->handle(app(MetaCommentAutomationService::class));
        $fresh = $event->fresh();

        // Both agree: matched, same rule
        $this->assertTrue(data_get($fresh->payload, 'decision_trace.matched'));
        $this->assertSame('greeting-rule', data_get($fresh->payload, 'decision_trace.rule.id'));
        $this->assertSame('processed', $fresh->status);
    }

    // ---------------------------------------------------------------
    //  13. Simulator makes zero outbound requests.
    // ---------------------------------------------------------------
    public function test_simulator_makes_zero_outbound_requests(): void
    {
        Http::fake();
        $user = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $user->id]);
        $user->forceFill(['business_id' => $business->id])->save();
        $bot = Bot::create(['business_profile_id' => $business->id, 'name' => 'Sim bot', 'status' => 'active']);
        $channel = Channel::factory()->create([
            'user_id' => $user->id, 'business_id' => $business->id, 'type' => 'facebook',
            'page_id' => 'sim-page', 'access_token' => 'sim-token', 'status' => 'connected',
        ]);
        $bot->channels()->attach($channel->id, [
            'is_primary' => true, 'comment_automation_enabled' => true, 'public_comment_reply_enabled' => true,
            'comment_settings' => json_encode(['rules' => [[
                'id' => 'sim-rule', 'name' => 'Sim Greeting', 'keywords' => ['Hi'],
                'excluded_keywords' => [], 'match' => 'equals', 'action' => 'public',
                'public_reply_variants' => ['Welcome!'], 'delay_minutes' => 0,
                'post_ids' => [], 'enabled' => true,
            ]]]),
        ]);
        Sanctum::actingAs($user);

        $this->withHeader('X-Business-Id', (string) $business->id)->postJson('/api/social-comments/simulate', [
            'bot_id' => $bot->id, 'channel_id' => $channel->id, 'comment_text' => 'Hi',
        ])->assertOk()->assertJsonPath('decision.matched', true);

        Http::assertNothingSent();
        $this->assertDatabaseCount('social_comment_events', 0);
        $this->assertDatabaseCount('messages', 0);
    }

    // ---------------------------------------------------------------
    //  14. Generic-comment fallback protection remains effective for
    //     unmatched comments (e.g. emoji-only, single-char noise).
    // ---------------------------------------------------------------
    public function test_generic_comment_filter_still_skips_unmatched_noise_comments(): void
    {
        Http::fake();
        $user = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $user->id]);
        $bot = Bot::create(['business_profile_id' => $business->id, 'name' => 'Noise filter bot', 'status' => 'active']);
        $channel = Channel::factory()->create([
            'user_id' => $user->id, 'business_id' => $business->id, 'type' => 'facebook',
            'page_id' => 'noise-page', 'access_token' => 'test-token', 'status' => 'connected',
        ]);
        $bot->channels()->attach($channel->id, [
            'is_primary' => true, 'comment_automation_enabled' => true, 'public_comment_reply_enabled' => true,
            'comment_settings' => json_encode(['rules' => [[
                'id' => 'price-rule', 'name' => 'Product info', 'keywords' => ['price'],
                'excluded_keywords' => [], 'match' => 'contains', 'action' => 'public',
                'public_reply_variants' => ['Check our catalog!'], 'delay_minutes' => 0,
                'post_ids' => [], 'enabled' => true,
            ]]]),
        ]);

        // Short noise comment (single char) that doesn't match any rule
        $event1 = $this->commentEvent($channel, $business, 'facebook', 'noise-x', 'x');
        (new ProcessSocialCommentEvent($event1->id))->handle(app(MetaCommentAutomationService::class));
        $this->assertSame('skipped', $event1->fresh()->status);
        $this->assertSame('generic_comment', $event1->fresh()->failure_code);

        // Emoji-only generic noise that doesn't match any rule
        $event2 = $this->commentEvent($channel, $business, 'facebook', 'noise-nice', 'nice');
        (new ProcessSocialCommentEvent($event2->id))->handle(app(MetaCommentAutomationService::class));
        $this->assertSame('skipped', $event2->fresh()->status);
        $this->assertSame('generic_comment', $event2->fresh()->failure_code);

        Http::assertNothingSent();
    }

    // ---------------------------------------------------------------
    //  Helpers
    // ---------------------------------------------------------------

    /**
     * Build the standard Greeting-rule scenario: business, bot, channel,
     * and an event with the given comment text.
     *
     * @return array{0: SocialCommentEvent, 1: Bot}
     */
    private function buildGreetingScenario(string $commentText): array
    {
        $user = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $user->id]);
        $bot = Bot::create(['business_profile_id' => $business->id, 'name' => 'Greeting bot', 'status' => 'active']);
        $channel = Channel::factory()->create([
            'user_id' => $user->id, 'business_id' => $business->id, 'type' => 'facebook',
            'page_id' => 'greeting-page', 'access_token' => 'test-token', 'status' => 'connected',
        ]);
        $bot->channels()->attach($channel->id, [
            'is_primary' => true, 'comment_automation_enabled' => true, 'public_comment_reply_enabled' => true,
            'comment_settings' => json_encode(['rules' => [[
                'id' => 'greeting-rule', 'name' => 'Greeting', 'keywords' => ['Hi'],
                'excluded_keywords' => [], 'match' => 'equals', 'action' => 'public',
                'public_reply_variants' => ['HI Welcome How we can You'], 'delay_minutes' => 0,
                'post_ids' => [], 'enabled' => true,
            ]]]),
        ]);
        $event = $this->commentEvent($channel, $business, 'facebook', 'greeting-' . strtolower($commentText), $commentText);

        return [$event, $bot];
    }

    private function commentEvent(Channel $channel, BusinessProfile $business, string $platform, string $commentId, string $text = 'Question?'): SocialCommentEvent
    {
        return SocialCommentEvent::create([
            'channel_id' => $channel->id, 'business_id' => $business->id, 'platform' => $platform,
            'event_type' => 'comment_created', 'account_id' => $platform === 'facebook' ? $channel->page_id : $channel->instagram_account_id,
            'comment_id' => $commentId, 'commenter_id' => 'customer-1', 'comment_text' => $text,
            'platform_created_at' => now(), 'status' => 'received',
        ]);
    }
}
