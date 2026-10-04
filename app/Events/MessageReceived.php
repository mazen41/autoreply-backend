<?php

namespace App\Events;

use App\Models\Conversation;
use App\Models\Message;
use App\Support\InboxBroadcastPayload;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessageReceived implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /** Upper bound for message content broadcast over Pusher (10 KB event limit). */
    public const MAX_CONTENT_LENGTH = InboxBroadcastPayload::MAX_CONTENT_LENGTH;

    public Message $message;
    public Conversation $conversation;
    public int $userId;

    public function __construct(Message $message, Conversation $conversation, int $userId)
    {
        $this->message = $message;
        $this->conversation = $conversation->load('channel:id,type,page_name', 'bot:id,name');
        $this->userId = $userId;
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('inbox.' . $this->userId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'message.received';
    }

    /**
     * Realtime contract for the `message.received` event (stable — do not
     * rename keys without updating the frontend consumer in useInbox.ts).
     *
     * Shape: { message: {...}, conversation: {...}, channel: {...},
     * bot: {...}|null, metadata: { content_truncated: bool } }.
     *  - `message` carries enough ApiMessage fields to append/patch the open
     *    timeline immediately; `content` is capped (MAX_CONTENT_LENGTH) so the
     *    whole payload stays well under Pusher's 10 KB limit, with
     *    `content_truncated` telling the consumer to refetch full text.
     *  - `conversation` carries the list-level fields so the conversation
     *    sidebar updates without a REST round-trip (sender_* live on the
     *    Conversation, not on Message), including bot identity and the
     *    unread count.
     *  - `channel`/`bot` mirror the identities at top level for consumers
     *    that only need identity.
     *
     * Payload shape is built by InboxBroadcastPayload — the single source
     * of the contract, shared with ConversationUpdated.
     */
    public function broadcastWith(): array
    {
        return InboxBroadcastPayload::messageReceived($this->message, $this->conversation);
    }
}
