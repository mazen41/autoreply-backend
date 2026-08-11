<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AnalyticsDaily extends Model
{
    protected $fillable = [
        'business_id',
        'date',
        'total_conversations',
        'total_messages',
        'ai_messages',
        'human_messages',
        'resolved_conversations',
        'avg_response_time_seconds',
        'new_users',
        'returning_users',
    ];

    protected $casts = [
        'date' => 'date',
        'avg_response_time_seconds' => 'float',
    ];

    public function business()
    {
        return $this->belongsTo(BusinessProfile::class, 'business_id');
    }

    public function scopeForBusiness($query, $businessId)
    {
        return $query->where('business_id', $businessId);
    }

    public function scopeForDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('date', [$startDate, $endDate]);
    }
}
