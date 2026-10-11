<?php

namespace Tests\Feature;

use App\Models\BusinessProfile;
use App\Models\Channel;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class InstagramRoutingTest extends TestCase
{
    use RefreshDatabase;

    private const APP_SECRET = 'test-meta-app-secret';
    private const WEBHOOK_URI = '/api/webhook/meta';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.meta.app_secret' => self::APP_SECRET]);
        Queue::fake();
        Http::fake();
    }

    private function postSigned(array $payload)
    {
        $raw = json_encode($payload);
        $sig = 'sha256=' . hash_hmac('sha256', $raw, self::APP_SECRET);

        return $this->call('POST', self::WEBHOOK_URI, [], [], [], $this->transformHeadersToServerVars([
            'Content-Type' => 'application/json',
            'X-Hub-Signature-256' => $sig,
        ]), $raw);
    }

    private function messagingPayload(string $object, string $entryId, string $mid): array
    {
        return [
            'object' => $object,
            'entry' => [[
                'id' => $entryId,
                'time' => 1,
                'messaging' => [[
                    'sender' => ['id' => 'CUSTOMER-' . $mid],
                    'recipient' => ['id' => $entryId],
                    'timestamp' => 1,
                    'message' => ['mid' => $mid, 'text' => 'hello there'],
                ]],
            ]],
        ];
    }

    private function makeBusinessWithChannels(): array
    {
        $user = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $user->id]);

        $facebook = Channel::factory()->create([
            'user_id' => $user->id, 'business_id' => $business->id,
            'type' => 'facebook', 'page_id' => 'PAGE1', 'status' => 'connected',
        ]);

        // Legacy row: an earlier callback copied the Facebook page ID onto it.
        $instagram = Channel::factory()->create([
            'user_id' => $user->id, 'business_id' => $business->id,
            'type' => 'instagram', 'instagram_account_id' => 'IG1',
            'page_id' => 'PAGE1', 'status' => 'connected',
        ]);

        return [$user, $business, $facebook, $instagram];
    }

    public function test_messenger_event_is_stored_on_facebook_channel_even_when_instagram_row_shares_page_id(): void
    {
        [, , $facebook, $instagram] = $this->makeBusinessWithChannels();

        $this->postSigned($this->messagingPayload('page', 'PAGE1', 'm.messenger.1'))
            ->assertStatus(200);

        $this->assertSame(1, Message::whereHas('conversation', fn ($q) => $q->where('channel_id', $facebook->id))->count());
        $this->assertSame(0, Message::whereHas('conversation', fn ($q) => $q->where('channel_id', $instagram->id))->count());
    }

    public function test_instagram_dm_is_routed_by_instagram_account_id(): void
    {
        [, , $facebook, $instagram] = $this->makeBusinessWithChannels();

        $this->postSigned($this->messagingPayload('instagram', 'IG1', 'm.instagram.1'))
            ->assertStatus(200);

        $this->assertSame(1, Message::whereHas('conversation', fn ($q) => $q->where('channel_id', $instagram->id))->count());
        $this->assertSame(0, Message::whereHas('conversation', fn ($q) => $q->where('channel_id', $facebook->id))->count());
    }

    public function test_unmatched_page_event_is_not_guessed_onto_an_instagram_row(): void
    {
        $user = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $user->id]);
        $orphanInstagram = Channel::factory()->create([
            'user_id' => $user->id, 'business_id' => $business->id,
            'type' => 'instagram', 'instagram_account_id' => 'IG2',
            'page_id' => 'PAGE2', 'status' => 'connected',
        ]);

        // No Facebook channel for PAGE9 and no Instagram row for PAGE9.
        $this->postSigned($this->messagingPayload('page', 'PAGE9', 'm.unknown.1'))
            ->assertStatus(200);

        $this->assertSame(0, Message::whereHas('conversation', fn ($q) => $q->where('channel_id', $orphanInstagram->id))->count());
    }

    public function test_repair_command_is_dry_run_by_default_and_clears_only_copied_page_ids(): void
    {
        [, , $facebook, $instagram] = $this->makeBusinessWithChannels();

        $this->artisan('channels:repair-instagram-page-ids')->assertExitCode(0);
        $this->assertSame('PAGE1', $instagram->fresh()->page_id);

        $this->artisan('channels:repair-instagram-page-ids --apply')->assertExitCode(0);
        $this->assertNull($instagram->fresh()->page_id);
        $this->assertSame('PAGE1', $facebook->fresh()->page_id);
    }
}