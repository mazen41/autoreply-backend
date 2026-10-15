<?php

namespace Tests\Feature;

use App\Models\Bot;
use App\Models\BusinessProfile;
use App\Models\Channel;
use App\Models\SocialCommentEvent;
use App\Models\User;
use App\Jobs\ProcessSocialCommentEvent;
use App\Services\MetaCommentAutomationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MetaCommentAutomationTest extends TestCase
{
    use RefreshDatabase;

    public function test_signed_facebook_feed_webhook_persists_comment_and_duplicate_is_deduplicated(): void
    {
        config(['services.meta.app_secret' => 'comment-test-secret']);
        $user = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $user->id]);
        $channel = Channel::factory()->create([
            'user_id' => $user->id, 'business_id' => $business->id, 'type' => 'facebook',
            'page_id' => 'page-comment-test', 'status' => 'connected',
        ]);
        $body = json_encode(['object' => 'page', 'entry' => [[
            'id' => 'page-comment-test',
            'changes' => [['field' => 'feed', 'value' => [
                'item' => 'comment', 'verb' => 'add', 'comment_id' => 'fb-comment-test-1',
                'post_id' => 'post-test-1', 'from' => ['id' => 'commenter-1'], 'message' => 'Do you ship here?',
                'created_time' => now()->toIso8601String(),
            ]]],
        ]]]);
        $signature = 'sha256=' . hash_hmac('sha256', $body, 'comment-test-secret');

        $this->call('POST', '/api/webhook/meta', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => $signature,
        ], $body)->assertOk();
        $this->call('POST', '/api/webhook/meta', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => $signature,
        ], $body)->assertOk();

        $this->assertSame(1, SocialCommentEvent::where('platform', 'facebook')->where('comment_id', 'fb-comment-test-1')->count());
        $this->assertDatabaseHas('social_comment_events', [
            'channel_id' => $channel->id, 'business_id' => $business->id,
            'account_id' => 'page-comment-test', 'commenter_id' => 'commenter-1',
            'comment_text' => 'Do you ship here?', 'post_id' => 'post-test-1',
        ]);
    }

    public function test_ambiguous_connected_account_fails_closed_without_persisting_comment(): void
    {
        $userA = User::factory()->create();
        $businessA = BusinessProfile::factory()->create(['user_id' => $userA->id]);
        $userB = User::factory()->create();
        $businessB = BusinessProfile::factory()->create(['user_id' => $userB->id]);
        foreach ([[$userA, $businessA], [$userB, $businessB]] as [$user, $business]) {
            Channel::factory()->create([
                'user_id' => $user->id, 'business_id' => $business->id, 'type' => 'instagram',
                'instagram_account_id' => 'duplicate-ig-account', 'status' => 'connected',
            ]);
        }

        $event = app(MetaCommentAutomationService::class)->receive('instagram', 'duplicate-ig-account', [
            'account_id' => 'duplicate-ig-account', 'comment_id' => 'ig-comment-ambiguous',
            'event_type' => 'comment_created', 'commenter_id' => 'customer-1', 'comment_text' => 'Hi',
        ], ['source' => 'instagram']);

        $this->assertNull($event);
        $this->assertDatabaseMissing('social_comment_events', ['comment_id' => 'ig-comment-ambiguous']);
    }

    public function test_signed_instagram_comment_webhook_persists_media_and_comment_timestamps(): void
    {
        config(['services.meta.app_secret' => 'comment-test-secret']);
        $user = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $user->id]);
        $channel = Channel::factory()->create([
            'user_id' => $user->id, 'business_id' => $business->id, 'type' => 'instagram',
            'instagram_account_id' => 'ig-comment-test', 'status' => 'connected',
        ]);
        $timestamp = now()->toIso8601String();
        $body = json_encode(['object' => 'instagram', 'entry' => [[
            'id' => 'ig-comment-test',
            'changes' => [['field' => 'comments', 'value' => [
                'id' => 'ig-comment-test-1', 'text' => 'Where can I find details?',
                'from' => ['id' => 'ig-commenter-1'], 'media' => ['id' => 'ig-media-1'], 'timestamp' => $timestamp,
            ]]],
        ]]]);
        $signature = 'sha256=' . hash_hmac('sha256', $body, 'comment-test-secret');

        $this->call('POST', '/api/webhook/meta', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => $signature,
        ], $body)->assertOk();

        $event = SocialCommentEvent::where('platform', 'instagram')->where('comment_id', 'ig-comment-test-1')->firstOrFail();
        $this->assertSame($channel->id, $event->channel_id);
        $this->assertSame('ig-media-1', $event->post_id);
        $this->assertSame('ig-commenter-1', $event->commenter_id);
        $this->assertEquals(now()->parse($timestamp)->timestamp, $event->platform_created_at->timestamp);
    }

    public function test_public_replies_use_platform_comment_edges_and_private_replies_never_use_dm_edge(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['id' => 'reply-123'], 200)]);
        $user = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $user->id]);
        $facebook = Channel::factory()->create([
            'user_id' => $user->id, 'business_id' => $business->id, 'type' => 'facebook',
            'page_id' => 'page-1', 'access_token' => 'fb-token',
        ]);
        $instagram = Channel::factory()->create([
            'user_id' => $user->id, 'business_id' => $business->id, 'type' => 'instagram',
            'instagram_account_id' => 'ig-1', 'access_token' => 'ig-token',
        ]);
        $fbEvent = $this->commentEvent($facebook, $business, 'facebook', 'fb-comment-1');
        $igEvent = $this->commentEvent($instagram, $business, 'instagram', 'ig-comment-1');
        $service = app(MetaCommentAutomationService::class);

        $this->assertTrue($service->publishPublic($fbEvent, $facebook, 'Hello')['success']);
        $this->assertTrue($service->publishPublic($igEvent, $instagram, 'Hello')['success']);
        $this->assertTrue($service->publishPrivate($fbEvent, $facebook, 'Private hello')['success']);
        $this->assertTrue($service->publishPrivate($igEvent, $instagram, 'Hello')['success']);

        $urls = Http::recorded()->map(fn ($pair) => $pair[0]->url())->all();
        $this->assertStringContainsString('/fb-comment-1/comments', $urls[0]);
        $this->assertStringContainsString('/ig-comment-1/replies', $urls[1]);
        $this->assertStringContainsString('/page-1/messages', $urls[2]);
        $this->assertStringContainsString('/ig-1/messages', $urls[3]);
        $requests = Http::recorded()->map(fn ($pair) => $pair[0]);
        $this->assertSame('fb-comment-1', $requests[2]->data()['recipient']['comment_id']);
        $this->assertSame('ig-comment-1', $requests[3]->data()['recipient']['comment_id']);
        $this->assertNotContains('https://graph.facebook.com/v26.0/me/messages', $urls);
    }

    public function test_private_reply_outside_eligibility_window_is_skipped_without_http_call(): void
    {
        Http::fake();
        $user = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $user->id]);
        $channel = Channel::factory()->create(['user_id' => $user->id, 'business_id' => $business->id, 'type' => 'instagram', 'instagram_account_id' => 'ig-2']);
        $event = $this->commentEvent($channel, $business, 'instagram', 'old-comment');
        $event->update(['platform_created_at' => now()->subDays(8)]);

        $result = app(MetaCommentAutomationService::class)->publishPrivate($event->fresh(), $channel, 'Hello');

        $this->assertTrue($result['skipped']);
        Http::assertNothingSent();
    }

    public function test_instagram_live_comment_private_reply_is_skipped(): void
    {
        Http::fake();
        $user = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $user->id]);
        $channel = Channel::factory()->create(['user_id' => $user->id, 'business_id' => $business->id, 'type' => 'instagram', 'instagram_account_id' => 'ig-live']);
        $event = $this->commentEvent($channel, $business, 'instagram', 'live-comment');
        $event->update(['is_live_comment' => true]);

        $result = app(MetaCommentAutomationService::class)->publishPrivate($event->fresh(), $channel, 'Hello');

        $this->assertTrue($result['skipped']);
        Http::assertNothingSent();
    }

    public function test_public_disclosure_guard_rejects_private_data_restricted_prices_and_known_hidden_products(): void
    {
        $service = app(MetaCommentAutomationService::class);
        $this->assertFalse($service->publicReplyAllowed('Your order #12345 is confirmed.', ['public_information' => ['general_business_info']]));
        $this->assertFalse($service->publicReplyAllowed('The cost is 25.00 SAR.', ['public_information' => []], [], ['25.00']));
        $this->assertFalse($service->publicReplyAllowed('The Gift Card is available.', ['public_information' => []], ['Gift Card']));
        $this->assertTrue($service->publicReplyAllowed('Thanks for your comment!', ['public_information' => []]));
    }

    public function test_settings_are_limited_to_the_current_business_and_bot_assignments(): void
    {
        $user = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $user->id]);
        $user->forceFill(['business_id' => $business->id])->save();
        $bot = Bot::create(['business_profile_id' => $business->id, 'name' => 'Comments', 'status' => 'active']);
        $channel = Channel::factory()->create(['user_id' => $user->id, 'business_id' => $business->id, 'type' => 'facebook', 'page_id' => 'page-settings']);
        $bot->channels()->attach($channel->id, ['is_primary' => true]);
        Sanctum::actingAs($user);

        $this->withHeader('X-Business-Id', (string) $business->id)->putJson("/api/bots/{$bot->id}/comment-automation", [
            'channels' => [[
                'channel_id' => $channel->id, 'enabled' => true, 'public_reply_enabled' => true,
                'private_reply_enabled' => false,
                'settings' => ['public_information' => ['general_business_info', 'product_names'], 'fallback_response' => 'Please message us.'],
            ]],
        ])->assertOk()->assertJsonPath('channels.0.enabled', true);

        $this->assertDatabaseHas('bot_channels', ['bot_id' => $bot->id, 'channel_id' => $channel->id, 'comment_automation_enabled' => 1]);
    }

    public function test_structured_comment_rules_round_trip_through_settings_api(): void
    {
        $user = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $user->id]);
        $user->forceFill(['business_id' => $business->id])->save();
        $bot = Bot::create(['business_profile_id' => $business->id, 'name' => 'Comments', 'status' => 'active']);
        $channel = Channel::factory()->create(['user_id' => $user->id, 'business_id' => $business->id, 'type' => 'facebook']);
        $bot->channels()->attach($channel->id, ['is_primary' => true]);
        Sanctum::actingAs($user);
        $rules = [[
            'id' => 'stable-rule-1', 'name' => 'Product information', 'keywords' => ['price'],
            'excluded_keywords' => ['spam'], 'match' => 'contains', 'action' => 'public',
            'public_reply_variants' => ['Thanks for asking!'], 'dm_message' => null,
            'delay_minutes' => 0, 'post_ids' => [], 'enabled' => true,
        ]];

        $this->withHeader('X-Business-Id', (string) $business->id)->putJson("/api/bots/{$bot->id}/comment-automation", [
            'channels' => [[
                'channel_id' => $channel->id, 'enabled' => true, 'public_reply_enabled' => true,
                'private_reply_enabled' => false, 'settings' => ['rules' => $rules],
            ]],
        ])->assertOk()
            ->assertJsonPath('channels.0.settings.schema_version', 1)
            ->assertJsonPath('channels.0.settings.rules.0.id', 'stable-rule-1');

        $savedSettings = json_decode($bot->channels()->first()->pivot->comment_settings, true);
        $this->assertSame(1, $savedSettings['schema_version']);
        $this->assertSame($rules, $savedSettings['rules']);
    }

    public function test_comment_settings_api_rejects_invalid_structured_rule_fields(): void
    {
        $user = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $user->id]);
        $user->forceFill(['business_id' => $business->id])->save();
        $bot = Bot::create(['business_profile_id' => $business->id, 'name' => 'Comments', 'status' => 'active']);
        $channel = Channel::factory()->create(['user_id' => $user->id, 'business_id' => $business->id, 'type' => 'facebook']);
        $bot->channels()->attach($channel->id, ['is_primary' => true]);
        Sanctum::actingAs($user);

        $this->withHeader('X-Business-Id', (string) $business->id)->putJson("/api/bots/{$bot->id}/comment-automation", [
            'channels' => [[
                'channel_id' => $channel->id, 'enabled' => true, 'public_reply_enabled' => true,
                'private_reply_enabled' => false,
                'settings' => ['rules' => [[
                    'id' => 'bad', 'name' => 'Bad rule', 'keywords' => [''], 'match' => 'fuzzy',
                    'action' => 'all', 'delay_minutes' => -1, 'post_ids' => [], 'enabled' => true,
                ]]],
            ]],
        ])->assertUnprocessable();
    }

    public function test_comment_settings_api_rejects_empty_keyword_array(): void
    {
        $user = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $user->id]);
        $user->forceFill(['business_id' => $business->id])->save();
        $bot = Bot::create(['business_profile_id' => $business->id, 'name' => 'Comments', 'status' => 'active']);
        $channel = Channel::factory()->create(['user_id' => $user->id, 'business_id' => $business->id, 'type' => 'facebook']);
        $bot->channels()->attach($channel->id, ['is_primary' => true]);
        Sanctum::actingAs($user);

        $this->withHeader('X-Business-Id', (string) $business->id)->putJson("/api/bots/{$bot->id}/comment-automation", [
            'channels' => [[
                'channel_id' => $channel->id, 'enabled' => true, 'public_reply_enabled' => true,
                'private_reply_enabled' => false, 'settings' => ['rules' => [[
                    'id' => 'empty', 'name' => 'Empty trigger', 'keywords' => [], 'match' => 'contains',
                    'action' => 'public', 'public_reply_variants' => ['Hello'], 'delay_minutes' => 0,
                    'post_ids' => [], 'enabled' => true,
                ]]],
            ]],
        ])->assertUnprocessable();
    }

    public function test_comment_simulator_is_business_scoped_and_never_sends_or_persists_an_event(): void
    {
        Http::fake();
        $user = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $user->id]);
        $user->forceFill(['business_id' => $business->id])->save();
        $bot = Bot::create(['business_profile_id' => $business->id, 'name' => 'Comment bot', 'status' => 'active']);
        $channel = Channel::factory()->create([
            'user_id' => $user->id, 'business_id' => $business->id, 'type' => 'facebook',
            'page_id' => 'simulation-page', 'access_token' => 'simulation-token', 'status' => 'connected',
        ]);
        $bot->channels()->attach($channel->id, [
            'is_primary' => true,
            'comment_automation_enabled' => true,
            'public_comment_reply_enabled' => true,
            'private_comment_reply_enabled' => false,
            'comment_settings' => json_encode(['trigger_keywords' => ['price'], 'excluded_keywords' => []]),
        ]);
        Sanctum::actingAs($user);

        $response = $this->withHeader('X-Business-Id', (string) $business->id)->postJson('/api/social-comments/simulate', [
            'bot_id' => $bot->id,
            'channel_id' => $channel->id,
            'comment_text' => 'What is the PRICE?',
            'post_id' => 'synthetic-post',
        ]);

        $response->assertOk()
            ->assertJsonPath('dry_run', true)
            ->assertJsonPath('decision.matched', true)
            ->assertJsonPath('decision.rule.id', 'legacy')
            ->assertJsonPath('decision.permitted_actions.0', 'public')
            ->assertJsonPath('private_reply_preview', null);
        Http::assertNothingSent();
        $this->assertDatabaseCount('social_comment_events', 0);
        $this->assertDatabaseCount('messages', 0);
    }

    public function test_comment_simulator_rejects_an_account_not_assigned_to_the_selected_bot(): void
    {
        $user = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $user->id]);
        $user->forceFill(['business_id' => $business->id])->save();
        $bot = Bot::create(['business_profile_id' => $business->id, 'name' => 'Comment bot', 'status' => 'active']);
        $channel = Channel::factory()->create(['user_id' => $user->id, 'business_id' => $business->id, 'type' => 'facebook']);
        Sanctum::actingAs($user);

        $this->withHeader('X-Business-Id', (string) $business->id)->postJson('/api/social-comments/simulate', [
            'bot_id' => $bot->id,
            'channel_id' => $channel->id,
            'comment_text' => 'synthetic comment',
        ])->assertUnprocessable();
    }

    public function test_saved_comment_regression_scenarios_can_be_rerun_without_outbound_requests(): void
    {
        Http::fake();
        $user = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $user->id]);
        $user->forceFill(['business_id' => $business->id])->save();
        $bot = Bot::create(['business_profile_id' => $business->id, 'name' => 'Scenario bot', 'status' => 'active']);
        $channel = Channel::factory()->create([
            'user_id' => $user->id, 'business_id' => $business->id, 'type' => 'facebook',
            'page_id' => 'scenario-page', 'access_token' => 'scenario-token', 'status' => 'connected',
        ]);
        $bot->channels()->attach($channel->id, [
            'is_primary' => true, 'comment_automation_enabled' => true,
            'public_comment_reply_enabled' => true, 'private_comment_reply_enabled' => false,
            'comment_settings' => json_encode(['trigger_keywords' => ['price'], 'excluded_keywords' => []]),
        ]);
        Sanctum::actingAs($user);

        $created = $this->withHeader('X-Business-Id', (string) $business->id)->postJson('/api/social-comments/scenarios', [
            'bot_id' => $bot->id,
            'channel_id' => $channel->id,
            'name' => 'Price keyword is detected',
            'input' => ['comment_text' => 'What is the price?'],
            'expected' => ['matched' => true, 'rule_id' => 'legacy', 'action' => 'public'],
        ])->assertCreated()->assertJsonPath('data.name', 'Price keyword is detected');
        $scenarioId = (int) $created->json('data.id');
        $incorrect = $this->withHeader('X-Business-Id', (string) $business->id)->postJson('/api/social-comments/scenarios', [
            'bot_id' => $bot->id,
            'channel_id' => $channel->id,
            'name' => 'Intentionally wrong expectation',
            'input' => ['comment_text' => 'What is the price?'],
            'expected' => ['matched' => false, 'action' => 'none'],
        ])->assertCreated();
        $incorrectScenarioId = (int) $incorrect->json('data.id');

        foreach ([1, 2] as $_) {
            $this->withHeader('X-Business-Id', (string) $business->id)->postJson('/api/social-comments/scenarios/run', [
                'scenario_ids' => [$scenarioId, $incorrectScenarioId],
            ])->assertOk()
                ->assertJsonPath('dry_run', true)
                ->assertJsonPath('summary.passed', 1)
                ->assertJsonPath('summary.failed', 1)
                ->assertJsonPath('results.0.actual.rule_id', 'legacy')
                ->assertJsonPath('results.1.passed', false);
        }

        Http::assertNothingSent();
        $this->assertDatabaseCount('social_comment_events', 0);
        $this->assertDatabaseCount('messages', 0);
    }

    public function test_comment_regression_scenarios_cannot_be_run_across_businesses(): void
    {
        $owner = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $owner->id]);
        $owner->forceFill(['business_id' => $business->id])->save();
        $bot = Bot::create(['business_profile_id' => $business->id, 'name' => 'Scenario bot', 'status' => 'active']);
        $channel = Channel::factory()->create(['user_id' => $owner->id, 'business_id' => $business->id, 'type' => 'facebook']);
        $bot->channels()->attach($channel->id, ['is_primary' => true, 'comment_automation_enabled' => true, 'public_comment_reply_enabled' => true]);
        $scenario = \App\Models\SocialCommentTestScenario::create([
            'business_id' => $business->id, 'bot_id' => $bot->id, 'channel_id' => $channel->id,
            'name' => 'Private scenario', 'input' => ['comment_text' => 'price'], 'expected' => ['matched' => true],
        ]);

        $other = User::factory()->create();
        $otherBusiness = BusinessProfile::factory()->create(['user_id' => $other->id]);
        $other->forceFill(['business_id' => $otherBusiness->id])->save();
        Sanctum::actingAs($other);

        $this->withHeader('X-Business-Id', (string) $otherBusiness->id)
            ->postJson('/api/social-comments/scenarios/run', ['scenario_ids' => [$scenario->id]])
            ->assertNotFound();
    }

    public function test_delayed_public_comment_reply_is_scheduled_without_sending_immediately(): void
    {
        Http::fake();
        Queue::fake();
        $user = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $user->id]);
        $bot = Bot::create(['business_profile_id' => $business->id, 'name' => 'Delayed comments', 'status' => 'active']);
        $channel = Channel::factory()->create([
            'user_id' => $user->id, 'business_id' => $business->id, 'type' => 'facebook',
            'page_id' => 'delayed-page', 'access_token' => 'test-token', 'status' => 'connected',
        ]);
        $bot->channels()->attach($channel->id, [
            'is_primary' => true, 'comment_automation_enabled' => true, 'public_comment_reply_enabled' => true,
            'comment_settings' => json_encode(['rules' => [[
                'id' => 'delay-rule', 'name' => 'Delayed reply', 'keywords' => ['price'], 'match' => 'contains',
                'action' => 'public', 'public_reply_variants' => ['Thanks!'], 'delay_minutes' => 10,
                'enabled' => true,
            ]]]),
        ]);
        $event = $this->commentEvent($channel, $business, 'facebook', 'delayed-comment');
        $event->update(['comment_text' => 'price please', 'status' => 'received']);

        (new ProcessSocialCommentEvent($event->id))->handle(app(MetaCommentAutomationService::class));

        $this->assertSame('scheduled', $event->fresh()->status);
        $this->assertNotNull(data_get($event->fresh()->payload, 'decision_trace.scheduled_for'));
        Queue::assertPushed(ProcessSocialCommentEvent::class, fn ($job) => $job->eventId === $event->id);
        Http::assertNothingSent();
    }

    public function test_retry_reuses_the_exact_public_reply_selected_before_the_first_send(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['id' => 'stable-public-reply'], 200)]);
        $user = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $user->id]);
        $bot = Bot::create(['business_profile_id' => $business->id, 'name' => 'Stable replies', 'status' => 'active']);
        $channel = Channel::factory()->create([
            'user_id' => $user->id, 'business_id' => $business->id, 'type' => 'facebook',
            'page_id' => 'stable-page', 'access_token' => 'test-token', 'status' => 'connected',
        ]);
        $bot->channels()->attach($channel->id, [
            'is_primary' => true, 'comment_automation_enabled' => true, 'public_comment_reply_enabled' => true,
            'comment_settings' => json_encode(['rules' => [[
                'id' => 'stable-rule', 'name' => 'Stable reply', 'keywords' => ['price'], 'match' => 'contains',
                'action' => 'public', 'public_reply_variants' => ['First variant', 'Second variant'],
                'delay_minutes' => 0, 'enabled' => true,
            ]]]),
        ]);
        $event = $this->commentEvent($channel, $business, 'facebook', 'stable-comment');
        $event->update(['comment_text' => 'price please']);
        $failedSend = \Mockery::mock(MetaCommentAutomationService::class);
        $failedSend->shouldReceive('publicReplyAllowed')->once()->andReturn(true);
        $failedSend->shouldReceive('publishPublic')->once()->andReturn(['success' => false, 'retryable' => true]);

        try {
            (new ProcessSocialCommentEvent($event->id))->handle($failedSend);
            $this->fail('A retryable provider failure must be rethrown to the queue.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('meta_transient_failure', $exception->getMessage());
        }

        $selectedReply = data_get($event->fresh()->payload, 'decision_trace.selected_public_reply_text');
        $this->assertContains($selectedReply, ['First variant', 'Second variant']);
        $bot->channels()->updateExistingPivot($channel->id, [
            'comment_settings' => json_encode(['rules' => [[
                'id' => 'stable-rule', 'name' => 'Stable reply', 'keywords' => ['price'], 'match' => 'contains',
                'action' => 'public', 'public_reply_variants' => ['Changed after retry'],
                'delay_minutes' => 0, 'enabled' => true,
            ]]]),
        ]);

        (new ProcessSocialCommentEvent($event->id))->handle(app(MetaCommentAutomationService::class));

        $this->assertSame('processed', $event->fresh()->status);
        $this->assertSame($selectedReply, Http::recorded()->first()[0]->data()['message']);
        Http::assertSentCount(1);
    }

    public function test_successful_worker_send_persists_a_truthful_public_reply_trace(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['id' => 'worker-public-reply'], 200)]);
        $user = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $user->id]);
        $bot = Bot::create(['business_profile_id' => $business->id, 'name' => 'Public comment bot', 'status' => 'active']);
        $channel = Channel::factory()->create([
            'user_id' => $user->id, 'business_id' => $business->id, 'type' => 'facebook',
            'page_id' => 'public-worker-page', 'access_token' => 'test-token', 'status' => 'connected',
        ]);
        $bot->channels()->attach($channel->id, [
            'is_primary' => true, 'comment_automation_enabled' => true, 'public_comment_reply_enabled' => true,
            'private_comment_reply_enabled' => true,
            'comment_settings' => json_encode(['rules' => [[
                'id' => 'public-only-rule', 'name' => 'Public response', 'keywords' => ['price'], 'match' => 'contains',
                'action' => 'both', 'public_reply_variants' => ['Thanks for asking!'], 'dm_message' => 'Not used',
                'delay_minutes' => 0, 'enabled' => true,
            ]]]),
        ]);
        $event = $this->commentEvent($channel, $business, 'facebook', 'public-worker-comment');
        $event->update(['comment_text' => 'What is the price?']);

        (new ProcessSocialCommentEvent($event->id))->handle(app(MetaCommentAutomationService::class));

        $fresh = $event->fresh();
        $this->assertSame('processed', $fresh->status);
        $this->assertSame('worker-public-reply', $fresh->public_reply_id);
        $this->assertSame('Thanks for asking!', data_get($fresh->payload, 'decision_trace.selected_public_reply_text'));
        $this->assertSame('sent', data_get($fresh->payload, 'decision_trace.public_reply.status'));
        $this->assertSame('skipped', data_get($fresh->payload, 'decision_trace.private_reply.status'));
        $this->assertDatabaseHas('messages', [
            'source' => 'comment_public_reply',
            'status' => 'sent',
        ]);
        Http::assertSentCount(1);
    }

    public function test_comment_deletion_cancels_scheduled_reply_and_worker_does_not_send(): void
    {
        config(['services.meta.app_secret' => 'comment-delete-secret']);
        Http::fake();
        Queue::fake();
        $user = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $user->id]);
        $channel = Channel::factory()->create([
            'user_id' => $user->id, 'business_id' => $business->id, 'type' => 'facebook',
            'page_id' => 'deleted-page', 'access_token' => 'test-token', 'status' => 'connected',
        ]);
        $event = $this->commentEvent($channel, $business, 'facebook', 'deleted-comment');
        $event->update(['status' => 'scheduled', 'payload' => ['decision_trace' => ['scheduled_for' => now()->addMinutes(5)->toIso8601String()]]]);

        $otherChannel = Channel::factory()->create([
            'user_id' => $user->id, 'business_id' => $business->id, 'type' => 'facebook',
            'page_id' => 'other-page', 'access_token' => 'test-token', 'status' => 'connected',
        ]);
        $otherEvent = $this->commentEvent($otherChannel, $business, 'facebook', 'deleted-comment');
        $otherEvent->update(['status' => 'scheduled']);
        $body = json_encode(['object' => 'page', 'entry' => [[
            'id' => 'deleted-page', 'changes' => [['field' => 'feed', 'value' => [
                'item' => 'comment', 'verb' => 'remove', 'comment_id' => 'deleted-comment',
            ]]],
        ]]]);
        $signature = 'sha256=' . hash_hmac('sha256', $body, 'comment-delete-secret');
        $this->call('POST', '/api/webhook/meta', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => $signature,
        ], $body)->assertOk();
        (new ProcessSocialCommentEvent($event->id))->handle(app(MetaCommentAutomationService::class));

        $this->assertSame('cancelled', $event->fresh()->status);
        $this->assertSame('comment_deleted', $event->fresh()->failure_code);
        $this->assertSame('scheduled', $otherEvent->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_terminal_failed_comment_event_is_not_retried_as_a_new_send(): void
    {
        Http::fake();
        $user = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $user->id]);
        $channel = Channel::factory()->create(['user_id' => $user->id, 'business_id' => $business->id, 'type' => 'facebook', 'page_id' => 'failed-terminal-page']);
        $event = $this->commentEvent($channel, $business, 'facebook', 'failed-terminal-comment');
        $event->update(['status' => 'failed', 'failure_code' => 'permission_denied']);

        (new ProcessSocialCommentEvent($event->id))->handle(app(MetaCommentAutomationService::class));

        $this->assertSame('failed', $event->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_comment_activity_separates_skips_failures_retries_and_pending_work(): void
    {
        $user = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $user->id]);
        $user->forceFill(['business_id' => $business->id])->save();
        $channel = Channel::factory()->create(['user_id' => $user->id, 'business_id' => $business->id, 'type' => 'facebook', 'page_id' => 'stats-page']);
        Sanctum::actingAs($user);

        foreach (['processed', 'skipped', 'cancelled', 'failed', 'retryable', 'scheduled', 'received'] as $index => $status) {
            $this->commentEvent($channel, $business, 'facebook', 'stats-comment-' . $index)->update(['status' => $status]);
        }

        $response = $this->withHeader('X-Business-Id', (string) $business->id)->getJson('/api/social-comments?status=needs_attention');
        $response->assertOk()
            ->assertJsonPath('stats.total', 7)
            ->assertJsonPath('stats.replied', 1)
            ->assertJsonPath('stats.skipped', 1)
            ->assertJsonPath('stats.cancelled', 1)
            ->assertJsonPath('stats.failed', 1)
            ->assertJsonPath('stats.retryable', 1)
            ->assertJsonPath('stats.pending', 2)
            ->assertJsonPath('stats.needs_attention', 4)
            ->assertJsonCount(4, 'data');
    }

    private function commentEvent(Channel $channel, BusinessProfile $business, string $platform, string $commentId): SocialCommentEvent
    {
        return SocialCommentEvent::create([
            'channel_id' => $channel->id, 'business_id' => $business->id, 'platform' => $platform,
            'event_type' => 'comment_created', 'account_id' => $platform === 'facebook' ? $channel->page_id : $channel->instagram_account_id,
            'comment_id' => $commentId, 'commenter_id' => 'person-1', 'comment_text' => 'Question?',
            'platform_created_at' => now(), 'status' => 'received',
        ]);
    }
}
