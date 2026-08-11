<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CalendarEvent extends Model
{
    protected $fillable = [
        'business_id',
        'conversation_id',
        'title',
        'description',
        'start_time',
        'end_time',
        'google_event_id',
        'status',
        'attendees',
    ];

    protected $casts = [
        'start_time' => 'datetime',
        'end_time' => 'datetime',
        'attendees' => 'array',
    ];

    public function business()
    {
        return $this->belongsTo(BusinessProfile::class, 'business_id');
    }

    public function conversation()
    {
        return $this->belongsTo(Conversation::class);
    }

    public function scopeUpcoming($query)
    {
        return $query->where('start_time', '>', now());
    }

    public function scopeConfirmed($query)
    {
        return $query->where('status', 'confirmed');
    }
}
