<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentCoachingLog extends Model
{
    protected $fillable = [
        'conversation_id',
        'agent_id',
        'business_id',
        'empathy_score',
        'sla_adherence_score',
        'accuracy_score',
        'overall_score',
        'constructive_feedback',
    ];

    protected $casts = [
        'empathy_score' => 'integer',
        'sla_adherence_score' => 'integer',
        'accuracy_score' => 'integer',
        'overall_score' => 'integer',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(BusinessProfile::class, 'business_id');
    }

    public function scopeForAgent($query, $agentId)
    {
        return $query->where('agent_id', $agentId);
    }

    public function scopeForBusiness($query, $businessId)
    {
        return $query->where('business_id', $businessId);
    }
}
