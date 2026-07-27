<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConversationTag extends Model
{
    protected $fillable = [
        'conversation_id',
        'tag',
        'intent',
        'confidence',
        'source',
    ];

    protected $casts = [
        'confidence' => 'float',
    ];

    public function conversation()
    {
        return $this->belongsTo(Conversation::class);
    }
}