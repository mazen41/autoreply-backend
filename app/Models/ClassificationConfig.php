<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClassificationConfig extends Model
{
    protected $fillable = [
        'business_id',
        'enabled',
        'categories',
        'priorities',
        'intents',
        'confidence_threshold',
        'auto_routing_enabled',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'categories' => 'array',
        'priorities' => 'array',
        'intents' => 'array',
        'confidence_threshold' => 'float',
        'auto_routing_enabled' => 'boolean',
    ];

    public function business()
    {
        return $this->belongsTo(BusinessProfile::class, 'business_id');
    }
}
