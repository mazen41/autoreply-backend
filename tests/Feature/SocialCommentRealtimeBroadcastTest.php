<?php

namespace Tests\Feature;

use App\Events\SocialCommentReceived;
use App\Jobs\ProcessSocialCommentEvent;
use App\Models\BusinessProfile;
use App\Models\Channel;
use App\Models\User;
use App\Services\MetaCommentAutomationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SocialCommentRealtimeBroadcastTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_meta_comment_is_broadcast_once_to_its_business_workspace(): void
    {
        Event::fake([SocialCommentReceived::class]);
        Queue::fake();

        $user = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $user->id]);
        Channel::factory()->create([
            'user_id' => $user->id,
            'business_id' => $business->id,
            'type' => 'facebook',
            'page_id' => 'test-page-id',
        ]);

        $service = app(MetaCommentAutomationService::class);
        $normalized = [
            'account_id' => 'test-page-id',
            'comment_id' => 'test-comment-id',
            'event_type' => 'comment_created',
            'comment_text' => 'Test comment',
        ];

        $first = $service->receive('facebook', 'test-page-id', $normalized, []);
        $duplicate = $service->receive('facebook', 'test-page-id', $normalized, []);

        $this->assertNotNull($first);
        $this->assertSame($first->id, $duplicate?->id);
        Event::assertDispatchedTimes(SocialCommentReceived::class, 1);
        Event::assertDispatched(SocialCommentReceived::class, fn (SocialCommentReceived $event): bool =>
            $event->businessId === $business->id && $event->commentEventId === $first->id
        );
        Queue::assertPushed(ProcessSocialCommentEvent::class);
    }
}
