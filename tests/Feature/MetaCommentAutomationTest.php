<?php

namespace Tests\Feature;

use App\Models\Bot;
use App\Models\BusinessProfile;
use App\Models\Channel;
use App\Models\SocialCommentEvent;
use App\Models\User;
use App\Services\MetaCommentAutomationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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
