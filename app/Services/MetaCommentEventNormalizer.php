<?php

namespace App\Services;

/** Converts Meta's distinct Page feed and Instagram comments change shapes to one event contract. */
class MetaCommentEventNormalizer
{
    public function facebookFeed(array $value, string $pageId = ''): ?array
    {
        $item = (string) ($value['item'] ?? 'comment');
        $verb = (string) ($value['verb'] ?? 'add');
        if ($item !== 'comment' || !in_array($verb, ['add', 'edited', 'remove'], true)) return null;

        $commentId = (string) ($value['comment_id'] ?? $value['id'] ?? '');
        if ($commentId === '') return null;

        return [
            'platform' => 'facebook',
            'event_type' => $verb === 'add' ? 'comment_created' : 'comment_' . $verb,
            'account_id' => (string) ($value['page_id'] ?? $pageId),
            'post_id' => isset($value['post_id']) ? (string) $value['post_id'] : null,
            'comment_id' => $commentId,
            'parent_comment_id' => isset($value['parent_id']) ? (string) $value['parent_id'] : null,
            'commenter_id' => isset($value['from']['id']) ? (string) $value['from']['id'] : (isset($value['sender_id']) ? (string) $value['sender_id'] : null),
            'comment_text' => isset($value['message']) ? trim((string) $value['message']) : (isset($value['text']) ? trim((string) $value['text']) : null),
            'platform_created_at' => $value['created_time'] ?? $value['timestamp'] ?? null,
            'is_live_comment' => false,
        ];
    }

    public function instagram(array $value, string $instagramAccountId = ''): ?array
    {
        $commentId = (string) ($value['id'] ?? $value['comment_id'] ?? '');
        if ($commentId === '') return null;

        $verb = (string) ($value['verb'] ?? 'add');
        if (!in_array($verb, ['add', 'edited', 'remove'], true)) return null;

        return [
            'platform' => 'instagram',
            'event_type' => $verb === 'add' ? 'comment_created' : 'comment_' . $verb,
            'account_id' => (string) ($value['instagram_account_id'] ?? $instagramAccountId),
            'post_id' => isset($value['media']['id']) ? (string) $value['media']['id'] : (isset($value['media_id']) ? (string) $value['media_id'] : null),
            'comment_id' => $commentId,
            'parent_comment_id' => isset($value['parent_id']) ? (string) $value['parent_id'] : null,
            'commenter_id' => isset($value['from']['id']) ? (string) $value['from']['id'] : (isset($value['user_id']) ? (string) $value['user_id'] : null),
            'comment_text' => isset($value['text']) ? trim((string) $value['text']) : null,
            'platform_created_at' => $value['timestamp'] ?? $value['created_time'] ?? null,
            'is_live_comment' => ($value['media_product_type'] ?? '') === 'LIVE' || !empty($value['is_live_comment']),
        ];
    }
}
