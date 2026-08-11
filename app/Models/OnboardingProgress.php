<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OnboardingProgress extends Model
{
    protected $fillable = [
        'user_id',
        'business_id',
        'step',
        'progress_percentage',
        'completed_steps',
        'completed_at',
    ];

    protected $casts = [
        'completed_steps' => 'array',
        'completed_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function business()
    {
        return $this->belongsTo(BusinessProfile::class, 'business_id');
    }

    public function isCompleted(): bool
    {
        return $this->progress_percentage === 100 && $this->completed_at !== null;
    }

    public function advanceStep(string $step): void
    {
        $completedSteps = $this->completed_steps ?? [];
        
        if (!in_array($step, $completedSteps)) {
            $completedSteps[] = $step;
        }

        $totalSteps = 5; // Total onboarding steps
        $progress = round((count($completedSteps) / $totalSteps) * 100);

        $this->update([
            'step' => $step,
            'completed_steps' => $completedSteps,
            'progress_percentage' => $progress,
        ]);

        if ($progress === 100) {
            $this->update(['completed_at' => now()]);
        }
    }
}
