<?php

namespace App\Events;

use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessageReceived implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public Message $message;
    public Conversation $conversation;
    public int $userId;

    public function __construct(Message $message, Conversation $conversation, int $userId)
    {
        $this->message = $message;
        $this->conversation = $conversation->load('channel:id,type,page_name');
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

    public function broadcastWith(): array
    {
        // Send only a lightweight payload to stay under Pusher's 10 KB limit.
        // The frontend fetches full details via the REST API using these IDs.
        return [
            'message_id'      => $this->message->id,
            'conversation_id' => $this->message->conversation_id,
            'channel_id'      => $this->conversation->channel_id ?? null,
            'channel_type'    => $this->conversation->channel->type ?? null,
            'sender_id'       => $this->message->sender_id ?? null,
            'sender_name'     => $this->message->sender_name ?? null,
            'direction'       => $this->message->direction ?? null,
            'preview'         => mb_substr(strip_tags($this->message->body ?? ''), 0, 120),
            'created_at'      => $this->message->created_at?->toISOString(),
        ];
    }
}
