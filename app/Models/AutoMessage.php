<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AutoMessage extends Model
{
    protected $fillable = [
        'business_id',
        'type',
        'message',
        'is_enabled',
        'timezone',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
    ];

    public function business()
    {
        return $this->belongsTo(BusinessProfile::class, 'business_id');
    }

    public function scopeEnabled($query)
    {
        return $query->where('is_enabled', true);
    }

    public function scopeByType($query, $type)
    {
        return $query->where('type', $type);
    }
}
