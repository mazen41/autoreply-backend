<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Message extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function (Message $message): void {
            // New outbound rows are immediately visible as queued, while the
            // legacy `pending` value remains readable for existing rows.
            if ($message->direction === 'outbound' && in_array($message->send_status, [null, 'pending'], true)) {
                $message->send_status = 'queued';
            }
        });

        static::created(function (Message $message): void {
            app(\App\Services\InboundConsentKeywordHandler::class)->handle($message);
        });

        static::updated(function (Message $message): void {
            if ($message->direction !== 'outbound'
                || !$message->wasChanged(['send_status', 'delivery_status', 'error_details'])) {
                return;
            }

            $conversation = $message->conversation()->with('channel:id,type,page_name,user_id', 'bot:id,name')->first();
            $userId = (int) ($conversation?->channel?->user_id ?? 0);
            if ($conversation && $userId > 0) {
                broadcast(new \App\Events\MessageReceived($message->fresh(), $conversation, $userId));
            }
        });
    }

    protected $fillable = [
        'conversation_id',
        'content',
        'content_html',
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
        'media_processing_status',
        'media_processing_reason',
        'mime_type',
        'file_name',
        'file_size',
        'duration',
        'whatsapp_message_id',
        'whatsapp_remote_jid',
        'whatsapp_from_me',
        'metadata',
        'confidence_score',
        'detected_dialect',
        'intent',
        'detected_language',
        'retry_count',
        'last_retry_at',
        'delivery_status',
        'error_details',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'is_ai' => 'boolean',
        'reactions' => 'array',
        'whatsapp_from_me' => 'boolean',
        'metadata' => 'array',
        'confidence_score' => 'float',
        'last_retry_at' => 'datetime',
    ];

    public function conversation()
    {
        return $this->belongsTo(Conversation::class);
    }

    public function aiReplyAudit()
    {
        return $this->hasOne(AiReplyAudit::class);
    }
}
