<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Segment extends Model
{
    protected $fillable = [
        'business_profile_id',
        'name',
        'rules',
    ];

    protected $casts = [
        'rules' => 'array',
    ];

    public function businessProfile(): BelongsTo
    {
        return $this->belongsTo(BusinessProfile::class, 'business_profile_id');
    }

    public function scopeForBusiness($query, $businessId)
    {
        return $query->where('business_profile_id', $businessId);
    }
}
