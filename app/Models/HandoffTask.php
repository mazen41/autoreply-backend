<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HandoffTask extends Model
{
    protected $fillable = [
        'conversation_id',
        'reason',
        'priority',
        'status',
        'assigned_to',
        'resolved_at',
        'due_at',
        'resolution_note',
    ];

    protected $casts = [
        'resolved_at' => 'datetime',
        'due_at' => 'datetime',
    ];

    public function conversation()
    {
        return $this->belongsTo(Conversation::class);
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }
}
