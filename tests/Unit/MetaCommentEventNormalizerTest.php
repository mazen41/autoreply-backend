<?php

namespace Tests\Unit;

use App\Services\MetaCommentEventNormalizer;
use PHPUnit\Framework\TestCase;

class MetaCommentEventNormalizerTest extends TestCase
{
    public function test_facebook_feed_comment_is_normalized_without_assuming_instagram_shape(): void
    {
        $event = (new MetaCommentEventNormalizer())->facebookFeed([
            'item' => 'comment', 'verb' => 'add', 'comment_id' => 'fb-comment-1',
            'post_id' => 'page_post_2', 'parent_id' => 'fb-parent',
            'from' => ['id' => 'person-3'], 'message' => 'Is this available?',
            'created_time' => '2026-10-13T12:00:00+0000',
        ], 'page-1');

        $this->assertSame('facebook', $event['platform']);
        $this->assertSame('page-1', $event['account_id']);
        $this->assertSame('fb-comment-1', $event['comment_id']);
        $this->assertSame('fb-parent', $event['parent_comment_id']);
        $this->assertSame('person-3', $event['commenter_id']);
        $this->assertSame('Is this available?', $event['comment_text']);
    }

    public function test_instagram_comment_is_normalized_from_media_and_from_fields(): void
    {
        $event = (new MetaCommentEventNormalizer())->instagram([
            'id' => 'ig-comment-1', 'text' => 'Beautiful!', 'from' => ['id' => 'person-4'],
            'media' => ['id' => 'ig-media-9'], 'timestamp' => '2026-10-13T12:00:00+0000',
        ], 'ig-account-2');

        $this->assertSame('instagram', $event['platform']);
        $this->assertSame('ig-account-2', $event['account_id']);
        $this->assertSame('ig-media-9', $event['post_id']);
        $this->assertSame('ig-comment-1', $event['comment_id']);
        $this->assertSame('person-4', $event['commenter_id']);
        $this->assertSame('Beautiful!', $event['comment_text']);
    }

    public function test_malformed_or_unsupported_events_are_ignored(): void
    {
        $normalizer = new MetaCommentEventNormalizer();
        $this->assertNull($normalizer->facebookFeed(['item' => 'like', 'verb' => 'add', 'comment_id' => 'x']));
        $this->assertNull($normalizer->facebookFeed(['item' => 'comment', 'verb' => 'add']));
        $this->assertNull($normalizer->instagram(['verb' => 'remove']));
    }
}
