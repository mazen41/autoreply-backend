<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Campaign extends Model
{
    protected $fillable = [
        'business_id',
        'channel_id',
        'name',
        'message',
        'status',
        'scheduled_at',
        'sent_at',
        'filters',
        'total_recipients',
        'sent_count',
        'failed_count',
        'error_message',
    ];

    protected $casts = [
        'filters' => 'array',
        'scheduled_at' => 'datetime',
        'sent_at' => 'datetime',
    ];

    public function business()
    {
        return $this->belongsTo(BusinessProfile::class, 'business_id');
    }

    public function channel()
    {
        return $this->belongsTo(Channel::class);
    }

    public function logs()
    {
        return $this->hasMany(CampaignLog::class);
    }

    public function scopeDraft($query)
    {
        return $query->where('status', 'draft');
    }

    public function scopeScheduled($query)
    {
        return $query->where('status', 'scheduled');
    }

    public function scopeSent($query)
    {
        return $query->where('status', 'sent');
    }
}
