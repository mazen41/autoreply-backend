<?php

namespace App\Support;

use App\Models\Conversation;
use App\Models\Message;

/**
 * Single source of truth for the inbox realtime payload contract.
 *
 * Stable contract — do NOT rename keys without updating the frontend
 * consumers (hooks/useInbox.ts). Both MessageReceived and
 * ConversationUpdated build their payloads here so the shapes can
 * never drift apart.
 *
 * ── message.received (private channel inbox.{userId}) ────────────
 * {
 *   message: {
 *     id, conversation_id, content, content_truncated, type,
 *     direction, status, is_ai, media_url, media_type, mime_type,
 *     file_name, file_size, created_at
 *   },
 *   conversation: {
 *     id, sender_id, sender_name, sender_email, subject, status,
 *     ai_enabled, requires_human, escalated_at, escalation_reason,
 *     last_message_at, bot_id, assigned_agent_id, assigned_at,
 *     channel_id, unread_count,
 *     channel: { id, type, page_name },
 *     bot: { id, name } | null
 *   },
 *   channel: { id, type, page_name },
 *   bot: { id, name } | null,
 *   metadata: { content_truncated: bool }
 * }
 *
 * ── conversation.updated (private channel inbox.{userId}) ────────
 * {
 *   conversation: { …same conversation shape as above… },
 *   tags: [ { id, tag } ],
 *   metadata: { event: 'conversation.updated' }
 * }
 *
 * Payload size: message content is capped at MAX_CONTENT_LENGTH so the
 * whole event stays well under Pusher's 10 KB per-event limit
 * (PUSHER_EVENT_LIMIT_BYTES). Consumers refetch full text when
 * content_truncated is true.
 */
class InboxBroadcastPayload
{
    /** Upper bound for message content broadcast over Pusher. */
    public const MAX_CONTENT_LENGTH = 2000;

    /** Pusher's per-event size limit (10 KB), documented for tests. */
    public const PUSHER_EVENT_LIMIT_BYTES = 10240;

    /**
     * Unread window: matches the `unread` filter in InboxController@index
     * (conversations with inbound messages in the last 24h).
     */
    public const UNREAD_WINDOW_HOURS = 24;

    public static function messagePayload(Message $message): array
    {
        $content = (string) ($message->content ?? '');
        $contentTruncated = mb_strlen($content) > self::MAX_CONTENT_LENGTH;

        return [
            'id'                => $message->id,
            'conversation_id'   => $message->conversation_id,
            'content'           => $contentTruncated
                ? rtrim(mb_substr($content, 0, self::MAX_CONTENT_LENGTH))
                : $content,
            'content_truncated' => $contentTruncated,
            'type'              => $message->type ?? 'text',
            'direction'         => $message->direction,
            'status'            => $message->status,
            'is_ai'             => (bool) $message->is_ai,
            'media_url'         => $message->media_url,
            'media_type'        => $message->media_type,
            'mime_type'         => $message->mime_type,
            'file_name'         => $message->file_name,
            'file_size'         => $message->file_size,
            'created_at'        => $message->created_at?->toISOString(),
        ];
    }

    /**
     * Normalized conversation shape shared by message.received and
     * conversation.updated. Carries everything the inbox list and the
     * conversation header need: sender state, conversation state, AI
     * state, agent assignment, channel identity, bot identity and the
     * unread count.
     */
    public static function conversationPayload(Conversation $conversation): array
    {
        $channel = $conversation->channel;
        $bot = $conversation->bot;

        return [
            'id'                => $conversation->id,
            'sender_id'         => $conversation->sender_id,
            'sender_name'       => $conversation->sender_name,
            'sender_email'      => $conversation->sender_email,
            'subject'           => $conversation->subject,
            'status'            => $conversation->status,
            'ai_enabled'        => $conversation->ai_enabled === null ? true : (bool) $conversation->ai_enabled,
            'requires_human'    => (bool) $conversation->requires_human,
            'escalated_at'      => $conversation->escalated_at?->toISOString(),
            'escalation_reason' => $conversation->escalation_reason,
            'last_message_at'   => $conversation->last_message_at?->toISOString(),
            'bot_id'            => $conversation->bot_id,
            'assigned_agent_id' => $conversation->assigned_agent_id,
            'assigned_at'       => $conversation->assigned_at?->toISOString(),
            'channel_id'        => $conversation->channel_id,
            'unread_count'      => self::unreadCount($conversation),
            'channel'           => [
                'id'        => $channel?->id,
                'type'      => $channel?->type,
                'page_name' => $channel?->page_name,
            ],
            'bot'               => $bot ? ['id' => $bot->id, 'name' => $bot->name] : null,
        ];
    }

    /**
     * Full message.received payload.
     */
    public static function messageReceived(Message $message, Conversation $conversation): array
    {
        $messagePayload = self::messagePayload($message);

        return [
            'message'      => $messagePayload,
            'conversation' => self::conversationPayload($conversation),
            // Top-level identity mirrors for consumers that don't need
            // the whole conversation object.
            'channel'      => $conversation->channel
                ? ['id' => $conversation->channel->id, 'type' => $conversation->channel->type, 'page_name' => $conversation->channel->page_name]
                : null,
            'bot'          => $conversation->bot
                ? ['id' => $conversation->bot->id, 'name' => $conversation->bot->name]
                : null,
            'metadata'     => [
                'content_truncated' => $messagePayload['content_truncated'],
            ],
        ];
    }

    /**
     * Full conversation.updated payload — fired on agent assignment,
     * AI toggle, status change, bot switch and tag changes.
     */
    public static function conversationUpdated(Conversation $conversation): array
    {
        return [
            'conversation' => self::conversationPayload($conversation),
            'tags'         => $conversation->tags()
                ->orderBy('id')
                ->get(['id', 'tag'])
                ->map(fn ($tag) => ['id' => $tag->id, 'tag' => $tag->tag])
                ->values()
                ->all(),
            'metadata'     => [
                'event' => 'conversation.updated',
            ],
        ];
    }

    /**
     * Unread messages for a conversation within the unread window.
     * Definition matches the `unread` filter in InboxController@index.
     */
    public static function unreadCount(Conversation $conversation): int
    {
        return (int) $conversation->messages()
            ->where('direction', 'inbound')
            ->where('created_at', '>=', now()->subHours(self::UNREAD_WINDOW_HOURS))
            ->count();
    }
}
