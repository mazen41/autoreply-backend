<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SocialCommentEvent extends Model
{
    protected $fillable = [
        'channel_id', 'business_id', 'bot_id', 'platform', 'event_type', 'account_id',
        'post_id', 'comment_id', 'parent_comment_id', 'commenter_id', 'comment_text',
        'platform_created_at', 'is_live_comment', 'payload', 'status', 'attempts', 'failure_code',
        'failure_reason', 'public_reply_id', 'private_reply_id', 'processed_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'platform_created_at' => 'datetime',
        'is_live_comment' => 'boolean',
        'processed_at' => 'datetime',
    ];

    public function channel()
    {
        return $this->belongsTo(Channel::class);
    }

    public function business()
    {
        return $this->belongsTo(BusinessProfile::class, 'business_id');
    }

    public function bot()
    {
        return $this->belongsTo(Bot::class);
    }
}
