<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class SequenceStepExecution extends Model
{
    use HasFactory;
    protected $fillable = [
        'sequence_id',
        'sequence_enrollment_id',
        'sequence_step_id',
        'status',
        'executed_at',
        'scheduled_at',
        'message_id',
        'error',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'executed_at' => 'datetime',
        'scheduled_at' => 'datetime',
    ];

    public function sequence()
    {
        return $this->belongsTo(Sequence::class);
    }

    public function enrollment()
    {
        return $this->belongsTo(SequenceEnrollment::class, 'sequence_enrollment_id');
    }

    public function step()
    {
        return $this->belongsTo(SequenceStep::class, 'sequence_step_id');
    }

    public function message()
    {
        return $this->belongsTo(Message::class);
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeProcessing($query)
    {
        return $query->where('status', 'processing');
    }

    public function scopeExecuted($query)
    {
        return $query->where('status', 'executed');
    }

    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    public function scopeSkipped($query)
    {
        return $query->where('status', 'skipped');
    }

    public function scopeReadyForExecution($query)
    {
        return $query->pending()
            ->where('scheduled_at', '<=', now());
    }

    public function markAsExecuted($messageId = null)
    {
        $this->status = 'executed';
        $this->executed_at = now();
        if ($messageId) {
            $this->message_id = $messageId;
        }
        $this->save();
    }

    public function markAsFailed($error = null)
    {
        $this->status = 'failed';
        $this->executed_at = now();
        if ($error) {
            $this->error = $error;
        }
        $this->save();
    }

    public function markAsSkipped($reason = null)
    {
        $this->status = 'skipped';
        $this->executed_at = now();
        if ($reason) {
            $this->metadata = array_merge($this->metadata ?? [], ['skip_reason' => $reason]);
        }
        $this->save();
    }

    public function schedule($delayInSeconds = 0)
    {
        $this->scheduled_at = now()->addSeconds($delayInSeconds);
        $this->save();
    }
}
