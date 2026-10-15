<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SocialCommentTestScenario extends Model
{
    protected $fillable = ['business_id', 'bot_id', 'channel_id', 'created_by', 'name', 'input', 'expected'];

    protected $casts = ['input' => 'array', 'expected' => 'array'];

    public function business()
    {
        return $this->belongsTo(BusinessProfile::class, 'business_id');
    }

    public function bot()
    {
        return $this->belongsTo(Bot::class);
    }

    public function channel()
    {
        return $this->belongsTo(Channel::class);
    }
}
