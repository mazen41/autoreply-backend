<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiActionLog extends Model
{
    protected $fillable = [
        'conversation_id',
        'message_id',
        'action_type',
        'action_payload',
        'status',
        'result',
        'error_message',
        'approved_by',
        'approved_at',
        'executed_at',
    ];

    protected $casts = [
        'action_payload' => 'array',
        'result' => 'array',
        'approved_at' => 'datetime',
        'executed_at' => 'datetime',
    ];

    public function conversation()
    {
        return $this->belongsTo(Conversation::class);
    }

    public function message()
    {
        return $this->belongsTo(Message::class);
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeExecuted($query)
    {
        return $query->where('status', 'executed');
    }

    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }
}
