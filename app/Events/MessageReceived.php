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

    /** Upper bound for message content broadcast over Pusher (10 KB event limit). */
    public const MAX_CONTENT_LENGTH = 2000;

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

    /**
     * Realtime contract for the `message.received` event (stable — do not
     * rename keys without updating the frontend consumer in useInbox.ts).
     *
     * Shape: { message: {...}, conversation: {...} }.
     *  - `message` carries enough ApiMessage fields to append/patch the open
     *    timeline immediately; `content` is capped (MAX_CONTENT_LENGTH) so the
     *    whole payload stays well under Pusher's 10 KB limit, with
     *    `content_truncated` telling the consumer to refetch full text.
     *  - `conversation` carries the list-level fields so the conversation
     *    sidebar updates without a REST round-trip (sender_* live on the
     *    Conversation, not on Message).
     */
    public function broadcastWith(): array
    {
        $content = (string) ($this->message->content ?? '');
        $contentTruncated = mb_strlen($content) > self::MAX_CONTENT_LENGTH;

        return [
            'message' => [
                'id'                => $this->message->id,
                'conversation_id'   => $this->message->conversation_id,
                'content'           => $contentTruncated
                    ? rtrim(mb_substr($content, 0, self::MAX_CONTENT_LENGTH))
                    : $content,
                'content_truncated' => $contentTruncated,
                'type'              => $this->message->type ?? 'text',
                'direction'         => $this->message->direction,
                'status'            => $this->message->status,
                'is_ai'             => (bool) $this->message->is_ai,
                'media_url'         => $this->message->media_url,
                'media_type'        => $this->message->media_type,
                'mime_type'         => $this->message->mime_type,
                'file_name'         => $this->message->file_name,
                'created_at'        => $this->message->created_at?->toISOString(),
            ],
            'conversation' => [
                'id'                => $this->conversation->id,
                'sender_id'         => $this->conversation->sender_id,
                'sender_name'       => $this->conversation->sender_name,
                'status'            => $this->conversation->status,
                'ai_enabled'        => $this->conversation->ai_enabled === null ? true : (bool) $this->conversation->ai_enabled,
                'last_message_at'   => $this->conversation->last_message_at?->toISOString(),
                'bot_id'            => $this->conversation->bot_id,
                'assigned_agent_id' => $this->conversation->assigned_agent_id,
                'channel_id'        => $this->conversation->channel_id,
                'channel'           => [
                    'id'        => $this->conversation->channel?->id,
                    'type'      => $this->conversation->channel?->type,
                    'page_name' => $this->conversation->channel?->page_name,
                ],
            ],
        ];
    }
}
