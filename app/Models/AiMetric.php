<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiMetric extends Model
{
    protected $fillable = [
        'business_id',
        'date',
        'total_ai_messages',
        'successful_ai_messages',
        'escalated_messages',
        'avg_confidence_score',
        'positive_feedback',
        'negative_feedback',
        'success_rate',
    ];

    protected $casts = [
        'date' => 'date',
        'avg_confidence_score' => 'float',
        'success_rate' => 'float',
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
