<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class SequenceStep extends Model
{
    use HasFactory;
    protected $fillable = [
        'sequence_id',
        'step_order',
        'step_type',
        'message',
        'config',
        'delay_hours',
        'delay_unit',
        'condition_config',
        'is_active',
    ];

    protected $casts = [
        'config' => 'array',
        'condition_config' => 'array',
        'is_active' => 'boolean',
    ];

    public function sequence()
    {
        return $this->belongsTo(Sequence::class);
    }

    public function executions()
    {
        return $this->hasMany(SequenceStepExecution::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('step_order');
    }

    public function scopeByType($query, $type)
    {
        return $query->where('step_type', $type);
    }

    public function isMessageStep()
    {
        return $this->step_type === 'message';
    }

    public function isDelayStep()
    {
        return $this->step_type === 'delay';
    }

    public function isConditionStep()
    {
        return $this->step_type === 'condition';
    }

    public function isActionStep()
    {
        return $this->step_type === 'action';
    }

    public function getDelayInHours()
    {
        if (!$this->isDelayStep()) return 0;
        
        $value = $this->delay_hours ?? 0;
        $unit = $this->delay_unit ?? 'hours';
        
        return match($unit) {
            'minutes' => $value / 60,
            'hours' => $value,
            'days' => $value * 24,
            default => $value,
        };
    }

    public function getDelayInSeconds(?string $timezone = null)
    {
        $delayInHours = $this->getDelayInHours();
        
        // If timezone is provided, respect business hours (placeholder for future implementation)
        if ($timezone) {
            // For now, just return the delay in seconds
            // Future: Check business hours in the specified timezone
            return $delayInHours * 3600;
        }
        
        return $delayInHours * 3600;
    }

    public function getNextStep()
    {
        return $this->sequence->steps()
            ->where('step_order', '>', $this->step_order)
            ->active()
            ->ordered()
            ->first();
    }

    public function getPreviousStep()
    {
        return $this->sequence->steps()
            ->where('step_order', '<', $this->step_order)
            ->active()
            ->orderBy('step_order', 'desc')
            ->first();
    }
}
