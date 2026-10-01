<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiMetric extends Model
{
    protected $fillable = [
        'business_profile_id',
        'bot_id',
        'provider',
        'model',
        'prompt_tokens',
        'completion_tokens',
        'total_tokens',
        'estimated_cost',
        'response_time_ms',
    ];

    protected $casts = [
        'prompt_tokens' => 'integer',
        'completion_tokens' => 'integer',
        'total_tokens' => 'integer',
        'estimated_cost' => 'float',
        'response_time_ms' => 'integer',
    ];

    public function businessProfile(): BelongsTo
    {
        return $this->belongsTo(BusinessProfile::class, 'business_profile_id');
    }

    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bot::class);
    }

    public function scopeForBusiness($query, $businessId)
    {
        return $query->where('business_profile_id', $businessId);
    }

    public function scopeForBot($query, $botId)
    {
        return $query->where('bot_id', $botId);
    }

    public function scopeForDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('created_at', [$startDate, $endDate]);
    }

    public function scopeForProvider($query, $provider)
    {
        return $query->where('provider', $provider);
    }
}
