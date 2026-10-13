<?php

namespace Tests\Feature;

use App\Models\BusinessProfile;
use App\Models\Channel;
use App\Models\User;
use App\Services\OAuthStateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MetaWebhookSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_facebook_callback_sends_only_page_fields_to_page_subscription_endpoint(): void
    {
        $user = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $user->id]);
        $state = app(OAuthStateService::class)->issue('facebook', $user->id, ['business_id' => $business->id]);

        Http::fake(function (ClientRequest $request) {
            $url = $request->url();

            if (str_contains($url, '/oauth/access_token')) {
                return Http::response([
                    'access_token' => str_contains($url, 'grant_type=fb_exchange_token') ? 'page-long-token' : 'user-token',
                ]);
            }

            if (str_contains($url, '/me/accounts')) {
                return Http::response(['data' => [[
                    'id' => 'test-page-id',
                    'name' => 'Test Page',
                    'access_token' => 'page-token',
                    'instagram_business_account' => ['id' => 'test-instagram-id'],
                ]]]);
            }

            if (str_contains($url, '/subscribed_apps')) {
                return Http::response(['success' => true]);
            }

            return Http::response([]);
        });

        $this->get('/api/channels/callback/facebook?code=test-code&state=' . $state)
            ->assertRedirectContains('facebook_connected');

        Http::assertSent(function (ClientRequest $request) {
            if ($request->method() !== 'POST' || !str_ends_with($request->url(), '/test-page-id/subscribed_apps')) {
                return false;
            }

            $fields = explode(',', (string) $request['subscribed_fields']);

            return in_array('feed', $fields, true)
                && in_array('message_reads', $fields, true)
                && !in_array('messaging_seen', $fields, true);
        });

        $page = Channel::where('type', 'facebook')->where('page_id', 'test-page-id')->firstOrFail();
        $instagram = Channel::where('type', 'instagram')->where('instagram_account_id', 'test-instagram-id')->firstOrFail();

        $this->assertSame('subscribed', $page->metadata['comment_webhook_status']);
        $this->assertSame('subscribed', $instagram->metadata['comment_webhook_status']);
    }
}
