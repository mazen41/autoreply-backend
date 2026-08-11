<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PendingAction extends Model
{
    protected $fillable = [
        'conversation_id',
        'action_type',
        'action_payload',
        'priority',
        'status',
        'error_message',
        'retry_count',
        'scheduled_at',
        'completed_at',
    ];

    protected $casts = [
        'action_payload' => 'array',
        'scheduled_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function conversation()
    {
        return $this->belongsTo(Conversation::class);
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeProcessing($query)
    {
        return $query->where('status', 'processing');
    }

    public function scopeHighPriority($query)
    {
        return $query->where('priority', 'high');
    }
}
