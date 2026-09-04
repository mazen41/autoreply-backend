<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class SequenceEnrollment extends Model
{
    use HasFactory;
    
    protected $table = 'sequence_enrollments';
    
    protected $fillable = [
        'sequence_id',
        'conversation_id',
        'current_step',
        'status',
        'started_at',
        'completed_at',
        'stopped_at',
        'next_execution_at',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'stopped_at' => 'datetime',
        'next_execution_at' => 'datetime',
    ];

    public function sequence()
    {
        return $this->belongsTo(Sequence::class);
    }

    public function conversation()
    {
        return $this->belongsTo(Conversation::class);
    }

    public function executions()
    {
        return $this->hasMany(SequenceStepExecution::class);
    }

    public function getCurrentStep()
    {
        return $this->sequence->steps()->where('step_order', $this->current_step)->first();
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public function scopeStopped($query)
    {
        return $query->where('status', 'stopped');
    }

    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    public function scopeReadyForExecution($query)
    {
        return $query->active()
            ->where('next_execution_at', '<=', now());
    }

    public function scopeForSequence($query, $sequenceId)
    {
        return $query->where('sequence_id', $sequenceId);
    }

    public function scopeForConversation($query, $conversationId)
    {
        return $query->where('conversation_id', $conversationId);
    }

    public function isActive()
    {
        return $this->status === 'active';
    }

    public function isCompleted()
    {
        return $this->status === 'completed';
    }

    public function isStopped()
    {
        return $this->status === 'stopped';
    }

    public function isFailed()
    {
        return $this->status === 'failed';
    }

    public function start()
    {
        $this->status = 'active';
        $this->started_at = now();
        $this->save();
    }

    public function complete()
    {
        $this->status = 'completed';
        $this->completed_at = now();
        $this->next_execution_at = null;
        $this->save();
    }

    public function stop($reason = null)
    {
        $this->status = 'stopped';
        $this->stopped_at = now();
        $this->next_execution_at = null;
        
        if ($reason) {
            $this->metadata = array_merge($this->metadata ?? [], ['stop_reason' => $reason]);
        }
        
        $this->save();
    }

    public function fail($error = null)
    {
        $this->status = 'failed';
        $this->next_execution_at = null;
        
        if ($error) {
            $this->metadata = array_merge($this->metadata ?? [], ['error' => $error]);
        }
        
        $this->save();
    }

    public function moveToNextStep()
    {
        $currentStep = $this->getCurrentStep();
        $nextStep = $currentStep?->getNextStep();
        
        if ($nextStep) {
            $this->current_step = $nextStep->step_order;
            $this->save();
            return $nextStep;
        }
        
        // No more steps, complete the enrollment
        $this->complete();
        return null;
    }

    public function scheduleNextExecution($delayInSeconds = 0)
    {
        $this->next_execution_at = now()->addSeconds($delayInSeconds);
        $this->save();
    }

    public function getProgress()
    {
        $totalSteps = $this->sequence->steps()->count();
        if ($totalSteps === 0) return 0;
        
        return round(($this->current_step / $totalSteps) * 100);
    }

    public function canContinue()
    {
        return $this->isActive() && $this->sequence->status === 'active';
    }
}
