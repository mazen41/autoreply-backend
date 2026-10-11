<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\DB;

class SequenceStepExecution extends Model
{
    use HasFactory;

    public const MAX_PER_ENROLLMENT = 50;
    protected $fillable = [
        'sequence_id',
        'sequence_enrollment_id',
        'sequence_step_id',
        'execution_key',
        'execution_index',
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

    /**
     * Generate a deterministic execution key for idempotent dispatch.
     * Hash of (enrollment_id, step_id, scheduled_at) — same inputs always
     * produce the same key, so duplicate dispatches are caught by the
     * unique index.
     */
    public static function generateKey(int $enrollmentId, int $stepId, string $scheduledAt): string
    {
        return hash('sha256', implode('|', [$enrollmentId, $stepId, $scheduledAt]));
    }

    /**
     * Create one visit using a monotonic enrollment index. Locking the
     * enrollment serializes concurrent schedulers and makes loop caps reliable.
     */
    public static function createForEnrollment(
        SequenceEnrollment $enrollment,
        SequenceStep $step,
        $scheduledAt,
        array $metadata = [],
    ): ?self {
        return DB::transaction(function () use ($enrollment, $step, $scheduledAt, $metadata) {
            $lockedEnrollment = SequenceEnrollment::query()
                ->whereKey($enrollment->id)
                ->lockForUpdate()
                ->first();

            if (!$lockedEnrollment || !$lockedEnrollment->canContinue()) {
                return null;
            }

            $lastIndex = (int) self::query()
                ->where('sequence_enrollment_id', $lockedEnrollment->id)
                ->max('execution_index');

            if ($lastIndex >= self::MAX_PER_ENROLLMENT) {
                $lockedEnrollment->stop('max_step_executions_reached');
                return null;
            }

            $executionIndex = $lastIndex + 1;
            $visitCount = self::query()
                ->where('sequence_enrollment_id', $lockedEnrollment->id)
                ->where('sequence_step_id', $step->id)
                ->count() + 1;

            return self::create([
                'sequence_id' => $lockedEnrollment->sequence_id,
                'sequence_enrollment_id' => $lockedEnrollment->id,
                'sequence_step_id' => $step->id,
                'execution_index' => $executionIndex,
                'execution_key' => hash('sha256', $lockedEnrollment->id . '|visit|' . $executionIndex),
                'status' => 'pending',
                'scheduled_at' => $scheduledAt,
                'metadata' => array_merge($metadata, [
                    'visit_count' => $visitCount,
                    'execution_index' => $executionIndex,
                ]),
            ]);
        });
    }
}
