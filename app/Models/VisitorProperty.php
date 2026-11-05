<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VisitorProperty extends Model
{
    use HasFactory;

    protected $table = 'visitor_properties';

    protected $fillable = [
        'property_uid',
        'business_id',
        'name',
        'domain',
        'allowed_domains',
        'type',
        'platform',
        'settings',
        'is_active',
        'verified_at',
    ];

    protected $casts = [
        'allowed_domains' => 'array',
        'settings' => 'array',
        'is_active' => 'boolean',
        'verified_at' => 'datetime',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(BusinessProfile::class, 'business_id');
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(VisitorSession::class, 'property_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(VisitorEvent::class, 'property_id');
    }

    public function identities(): HasMany
    {
        return $this->hasMany(VisitorIdentity::class, 'property_id');
    }

    public function conversions(): HasMany
    {
        return $this->hasMany(VisitorConversion::class, 'property_id');
    }
}
