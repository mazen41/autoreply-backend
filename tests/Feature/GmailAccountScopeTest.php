<?php

namespace Tests\Feature;

use App\Jobs\PollGmailInbox;
use App\Models\BusinessProfile;
use App\Models\Channel;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class GmailAccountScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_same_gmail_address_can_be_connected_separately_to_two_businesses(): void
    {
        $user = User::factory()->create();
        $businesses = [
            BusinessProfile::factory()->create(['user_id' => $user->id]),
            BusinessProfile::factory()->create(['user_id' => $user->id]),
        ];
        foreach ($businesses as $business) {
            Channel::factory()->create([
                'user_id' => $user->id,
                'business_id' => $business->id,
                'type' => 'gmail',
                'page_id' => 'shared-mailbox@example.test',
                'page_name' => 'shared-mailbox@example.test',
                'status' => 'connected',
            ]);
        }

        $channels = Channel::where('type', 'gmail')->where('page_id', 'shared-mailbox@example.test')->get();
        $this->assertCount(2, $channels);
        $this->assertCount(2, $channels->pluck('business_id')->unique());

        Log::shouldReceive('warning')->once()->withArgs(fn ($message, $context) =>
            $message === 'Gmail poll: duplicate mailbox connections across workspaces were skipped'
            && $context['skipped_connections'] === 2
        );
        Log::shouldReceive('info')->once()->withArgs(fn ($message, $context) =>
            $message === 'Gmail poll: no eligible connected accounts'
            && $context['connected_accounts'] === 2
        );
        (new PollGmailInbox())->handle();
        $this->assertDatabaseHas('system_health_logs', [
            'component' => 'gmail',
            'message' => 'A Gmail account poll reported a failure.',
        ]);
    }

    public function test_poll_does_not_assign_an_arbitrary_business_to_legacy_gmail_account(): void
    {
        $user = User::factory()->create();
        BusinessProfile::factory()->create(['user_id' => $user->id]);
        BusinessProfile::factory()->create(['user_id' => $user->id]);
        $channel = Channel::factory()->create([
            'user_id' => $user->id,
            'business_id' => null,
            'type' => 'gmail',
            'page_id' => 'legacy-mailbox@example.test',
            'status' => 'connected',
        ]);

        Log::shouldReceive('info')->once()->withArgs(fn ($message, $context) =>
            $message === 'Gmail poll: no eligible connected accounts'
            && $context['connected_accounts'] === 1
            && $context['missing_business_scope'] === 1
        );
        (new PollGmailInbox())->handle();

        $this->assertNull($channel->fresh()->business_id);
    }

    public function test_gmail_provider_ids_are_deduplicated_per_connected_account(): void
    {
        $user = User::factory()->create();
        $businessA = BusinessProfile::factory()->create(['user_id' => $user->id]);
        $businessB = BusinessProfile::factory()->create(['user_id' => $user->id]);
        $channelA = Channel::factory()->create(['user_id' => $user->id, 'business_id' => $businessA->id, 'type' => 'gmail']);
        $channelB = Channel::factory()->create(['user_id' => $user->id, 'business_id' => $businessB->id, 'type' => 'gmail']);

        foreach ([$channelA, $channelB] as $channel) {
            Message::factory()->create([
                'channel_id' => $channel->id,
                'conversation_id' => \App\Models\Conversation::factory()->create([
                    'business_id' => $channel->business_id,
                    'channel_id' => $channel->id,
                ])->id,
                'direction' => 'inbound',
                'platform_message_id' => 'gmail-provider-id',
            ]);
        }

        $this->assertDatabaseCount('messages', 2);
        $this->assertSame(2, Message::where('platform_message_hash', hash('sha256', 'gmail-provider-id'))
            ->distinct('channel_id')->count('channel_id'));
    }
}
