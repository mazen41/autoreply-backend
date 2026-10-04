<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AutomationWorkflow extends Model
{
    use HasFactory;
    protected $fillable = [
        'user_id',
        'business_id',
        'bot_id',
        'name',
        'description',
        'active',
        'trigger_config',
        'conditions',
        'actions_config',
        'executions_count',
        'last_executed_at',
    ];

    protected $casts = [
        'active' => 'boolean',
        'trigger_config' => 'array',
        'conditions' => 'array',
        'actions_config' => 'array',
        'last_executed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(BusinessProfile::class, 'business_id');
    }

    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bot::class);
    }

    public function executions(): HasMany
    {
        return $this->hasMany(WorkflowExecution::class, 'workflow_id');
    }

    public function scopeForBusiness($query, $businessId)
    {
        return $query->where('business_id', $businessId);
    }

    /**
     * Bot-scope filter for execution eligibility.
     *
     * Backward-compatible semantics:
     *   - $botId null  → only business-global workflows (bot_id IS NULL):
     *     a bot-specific workflow never fires for a bot-less conversation.
     *   - $botId set   → global workflows plus that bot's workflows:
     *     existing (bot_id NULL) rows keep running everywhere.
     */
    public function scopeForBotScope($query, ?int $botId)
    {
        if ($botId === null) {
            return $query->whereNull('bot_id');
        }

        return $query->where(function ($q) use ($botId) {
            $q->whereNull('bot_id')->orWhere('bot_id', $botId);
        });
    }

    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    public function scopeInactive($query)
    {
        return $query->where('active', false);
    }
}