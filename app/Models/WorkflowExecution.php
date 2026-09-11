<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkflowExecution extends Model
{
    protected $fillable = [
        'workflow_id',
        'conversation_id',
        'business_id',
        'status',
        'trigger_data',
        'results',
        'error_message',
        'test_mode',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'trigger_data' => 'array',
        'results' => 'array',
        'test_mode' => 'boolean',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(AutomationWorkflow::class, 'workflow_id');
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(BusinessProfile::class, 'business_id');
    }

    public function scopeForBusiness($query, $businessId)
    {
        return $query->where('business_id', $businessId);
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    public function scopeTest($query)
    {
        return $query->where('test_mode', true);
    }

    public function scopeLive($query)
    {
        return $query->where('test_mode', false);
    }
}
