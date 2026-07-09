<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Message extends Model
{
    protected $fillable = [
        'conversation_id',
        'content',
        'type',
        'direction',
        'status',
        'is_ai',
        'source',
        'send_status',
        'gmail_message_id',
    ];

    protected $casts = [
        'is_ai' => 'boolean',
    ];

    public function conversation()
    {
        return $this->belongsTo(Conversation::class);
    }
}
