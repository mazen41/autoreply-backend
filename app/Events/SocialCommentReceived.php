<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Notify the active business workspace that a new social comment was saved. */
class SocialCommentReceived implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $businessId,
        public int $commentEventId,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('business.' . $this->businessId)];
    }

    public function broadcastAs(): string
    {
        return 'social-comment.received';
    }

    public function broadcastWith(): array
    {
        // The client refetches through the authenticated API; do not put
        // commenter data or comment text on the realtime broadcast payload.
        return [
            'business_id' => $this->businessId,
            'comment_event_id' => $this->commentEventId,
        ];
    }
}
