<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SequenceUser extends Model
{
    protected $fillable = [
        'sequence_id',
        'conversation_id',
        'current_step',
        'status',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function sequence()
    {
        return $this->belongsTo(Sequence::class);
    }

    public function conversation()
    {
        return $this->belongsTo(Conversation::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public function scopePaused($query)
    {
        return $query->where('status', 'paused');
    }
}
