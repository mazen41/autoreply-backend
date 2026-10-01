<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_profile_id',
        'name',
        'phone',
        'email',
        'avatar',
        'lead_score',
        'tags',
        'custom_fields',
    ];

    protected $casts = [
        'tags' => 'array',
        'custom_fields' => 'array',
        'lead_score' => 'integer',
    ];

    public function businessProfile(): BelongsTo
    {
        return $this->belongsTo(BusinessProfile::class, 'business_profile_id');
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    public function notes(): HasMany
    {
        return $this->hasMany(CustomerNote::class)->orderBy('created_at', 'desc');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(CustomerOrder::class);
    }
}
