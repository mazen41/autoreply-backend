<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MessageCorrection extends Model
{
    protected $table = 'message_corrections';

    protected $fillable = [
        'original_message_id',
        'ai_draft',
        'human_correction',
        'approved',
        'approved_by',
        'approved_at',
        'learning_type',
    ];

    protected $casts = [
        'approved' => 'boolean',
        'approved_at' => 'datetime',
    ];

    public function originalMessage()
    {
        return $this->belongsTo(Message::class, 'original_message_id');
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}