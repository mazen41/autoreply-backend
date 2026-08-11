<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WebChatSession extends Model
{
    protected $fillable = [
        'business_id',
        'user_id',
        'session_id',
        'visitor_name',
        'visitor_email',
        'page_url',
        'ip_address',
        'user_agent',
        'is_online',
        'last_activity_at',
    ];

    protected $casts = [
        'is_online' => 'boolean',
        'last_activity_at' => 'datetime',
    ];

    public function business()
    {
        return $this->belongsTo(BusinessProfile::class, 'business_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function conversation()
    {
        return $this->hasOne(Conversation::class);
    }

    public function scopeOnline($query)
    {
        return $query->where('is_online', true);
    }

    public function scopeOffline($query)
    {
        return $query->where('is_online', false);
    }
}
