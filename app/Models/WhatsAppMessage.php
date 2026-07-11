<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsAppMessage extends Model
{
    protected $fillable = [
        'whatsapp_instance_id',
        'user_id',
        'message_id',
        'remote_message_id',
        'direction',
        'from_phone',
        'from_name',
        'to_phone',
        'body',
        'message_type',
        'media',
        'metadata',
        'status',
        'sent_at',
        'delivered_at',
        'read_at',
    ];

    protected $casts = [
        'media' => 'array',
        'metadata' => 'json',
        'sent_at' => 'datetime',
        'delivered_at' => 'datetime',
        'read_at' => 'datetime',
    ];

    public function whatsappInstance(): BelongsTo
    {
        return $this->belongsTo(WhatsAppInstance::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeIncoming($query)
    {
        return $query->where('direction', 'incoming');
    }

    public function scopeOutgoing($query)
    {
        return $query->where('direction', 'outgoing');
    }

    public function scopeForInstance($query, $instanceId)
    {
        return $query->where('whatsapp_instance_id', $instanceId);
    }

    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }
}
