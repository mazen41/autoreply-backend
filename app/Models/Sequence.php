<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Sequence extends Model
{
    protected $fillable = [
        'business_id',
        'name',
        'trigger_type',
        'trigger_config',
        'is_active',
    ];

    protected $casts = [
        'trigger_config' => 'array',
        'is_active' => 'boolean',
    ];

    public function business()
    {
        return $this->belongsTo(BusinessProfile::class, 'business_id');
    }

    public function steps()
    {
        return $this->hasMany(SequenceStep::class)->orderBy('step_order');
    }

    public function users()
    {
        return $this->hasMany(SequenceUser::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeByTrigger($query, $triggerType)
    {
        return $query->where('trigger_type', $triggerType);
    }
}
