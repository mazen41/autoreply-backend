<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Sequence extends Model
{
    use HasFactory;
    protected $fillable = [
        'business_id',
        'name',
        'description',
        'trigger_type',
        'trigger_config',
        'channel',
        'status',
        'settings',
        'timezone',
        'business_hours',
    ];

    protected $casts = [
        'trigger_config' => 'array',
        'settings' => 'array',
        'business_hours' => 'array',
    ];

    protected static function boot()
    {
        parent::boot();

        static::saving(function ($sequence) {
            // Only allow supported channels
            $supportedChannels = ['whatsapp', 'telegram', 'email'];
            if ($sequence->channel && !in_array($sequence->channel, $supportedChannels)) {
                throw new \Exception("Channel '{$sequence->channel}' is not supported. Supported channels: " . implode(', ', $supportedChannels));
            }

            // Only allow supported trigger types
            $supportedTriggerTypes = ['manual', 'new_user', 'tag_added', 'no_reply', 'order_created'];
            if ($sequence->trigger_type && !in_array($sequence->trigger_type, $supportedTriggerTypes)) {
                throw new \Exception("Trigger type '{$sequence->trigger_type}' is not supported. Supported types: " . implode(', ', $supportedTriggerTypes));
            }

            // Set default timezone if not provided
            if (!$sequence->timezone) {
                $sequence->timezone = 'UTC';
            }
        });
    }

    public function business()
    {
        return $this->belongsTo(BusinessProfile::class, 'business_id');
    }

    public function steps()
    {
        return $this->hasMany(SequenceStep::class)->orderBy('step_order');
    }

    public function enrollments()
    {
        return $this->hasMany(SequenceEnrollment::class);
    }

    public function activeEnrollments()
    {
        return $this->hasMany(SequenceEnrollment::class)->where('status', 'active');
    }

    public function stepExecutions()
    {
        return $this->hasManyThrough(SequenceStepExecution::class, SequenceEnrollment::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeDraft($query)
    {
        return $query->where('status', 'draft');
    }

    public function scopePaused($query)
    {
        return $query->where('status', 'paused');
    }

    public function scopeArchived($query)
    {
        return $query->where('status', 'archived');
    }

    public function scopeForBusiness($query, $businessId)
    {
        return $query->where('business_id', $businessId);
    }

    public function scopeWithTrigger($query, $triggerType)
    {
        return $query->where('trigger_type', $triggerType);
    }

    public function canBeActivated()
    {
        return $this->status === 'draft' || $this->status === 'paused';
    }

    public function canBePaused()
    {
        return $this->status === 'active';
    }

    public function canBeArchived()
    {
        return $this->status !== 'archived';
    }

    public function activate()
    {
        $this->status = 'active';
        $this->save();
    }

    public function pause()
    {
        $this->status = 'paused';
        $this->save();
    }

    public function archive()
    {
        $this->status = 'archived';
        $this->save();
    }

    public function getTotalEnrollments()
    {
        return $this->enrollments()->count();
    }

    public function getActiveEnrollments()
    {
        return $this->activeEnrollments()->count();
    }

    public function getCompletedEnrollments()
    {
        return $this->enrollments()->where('status', 'completed')->count();
    }

    public function getConversionRate()
    {
        $total = $this->getTotalEnrollments();
        if ($total === 0) return 0;
        
        $completed = $this->getCompletedEnrollments();
        return round(($completed / $total) * 100);
    }
}
