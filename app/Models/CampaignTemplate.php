<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CampaignTemplate extends Model
{
    protected $fillable = [
        'business_profile_id',
        'title',
        'channel_type',
        'body',
        'variables',
        'category',
    ];

    protected $casts = [
        'variables' => 'array',
    ];

    public function businessProfile(): BelongsTo
    {
        return $this->belongsTo(BusinessProfile::class, 'business_profile_id');
    }

    public function scopeForBusiness($query, $businessId)
    {
        return $query->where('business_profile_id', $businessId);
    }

    public function scopeForChannel($query, string $channelType)
    {
        return $query->where('channel_type', $channelType);
    }

    public function scopeForCategory($query, string $category)
    {
        return $query->where('category', $category);
    }
}
