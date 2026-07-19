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
        'reactions',
        'media_url',
        'media_type',
        'mime_type',
        'file_name',
        'file_size',
        'duration',
        'whatsapp_message_id',
        'whatsapp_remote_jid',
        'whatsapp_from_me',
        'metadata',
    ];

    protected $casts = [
        'is_ai' => 'boolean',
        'reactions' => 'array',
        'whatsapp_from_me' => 'boolean',
        'metadata' => 'array',
    ];

    public function conversation()
    {
        return $this->belongsTo(Conversation::class);
    }
}
