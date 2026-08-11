<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Conversation extends Model
{
    protected $fillable = [
        'channel_id',
        'business_id',
        'sender_id',
        'sender_name',
        'sender_email',
        'subject',
        'status',
        'ai_enabled',
        'requires_human',
        'escalated_at',
        'escalation_reason',
        'escalation_notified',
        'last_message_at',
        'assigned_agent_id',
        'assigned_at',
    ];

    protected $casts = [
        'last_message_at'      => 'datetime',
        'escalated_at'         => 'datetime',
        'assigned_at'          => 'datetime',
        'ai_enabled'           => 'boolean',
        'requires_human'       => 'boolean',
        'escalation_notified'  => 'boolean',
    ];

    public function channel()
    {
        return $this->belongsTo(Channel::class);
    }

    public function business()
    {
        return $this->belongsTo(BusinessProfile::class, 'business_id');
    }

    public function messages()
    {
        return $this->hasMany(Message::class);
    }

    public function latestMessage()
    {
        return $this->hasOne(Message::class)->latestOfMany();
    }

    public function business()
    {
        return $this->belongsTo(BusinessProfile::class, 'business_id');
    }

    public function assignedAgent()
    {
        return $this->belongsTo(User::class, 'assigned_agent_id');
    }
}
