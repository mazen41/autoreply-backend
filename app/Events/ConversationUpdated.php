<?php

namespace App\Events;

use App\Models\Conversation;
use App\Support\InboxBroadcastPayload;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when a conversation's mutable state changes outside of
 * incoming messages: agent assignment, AI toggle, status change,
 * bot switch, tag add/remove.
 *
 * Broadcast on the channel owner's private inbox channel so every
 * agent viewing the inbox sees the change immediately — no manual
 * refresh required.
 *
 * Contract (built by InboxBroadcastPayload::conversationUpdated):
 * {
 *   conversation: { …normalized conversation incl. channel, bot,
 *                   unread_count, assigned_agent_id, ai_enabled… },
 *   tags: [ { id, tag } ],
 *   metadata: { event: 'conversation.updated' }
 * }
 */
class ConversationUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public Conversation $conversation;
    public int $userId;

    public function __construct(Conversation $conversation, ?int $userId = null)
    {
        $this->conversation = $conversation->load('channel:id,type,page_name,user_id', 'bot:id,name');
        $this->userId = $userId ?? (int) ($conversation->channel->user_id ?? 0);
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('inbox.' . $this->userId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'conversation.updated';
    }

    public function broadcastWith(): array
    {
        return InboxBroadcastPayload::conversationUpdated($this->conversation);
    }
}
