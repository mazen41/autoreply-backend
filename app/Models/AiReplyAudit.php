<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiReplyAudit extends Model
{
    protected $fillable = [
        'message_id',
        'business_id',
        'conversation_id',
        'bot_id',
        'commerce_connection_id',
        'agent_version',
        'knowledge_file_ids',
        'knowledge_chunk_ids',
        'prompt_version',
        'ai_provider',
        'ai_model',
        'confidence',
        'escalation_decision',
        'escalation_reason',
        'consent_basis',
    ];

    protected $casts = [
        'knowledge_file_ids' => 'array',
        'knowledge_chunk_ids' => 'array',
        'confidence' => 'float',
        'escalation_decision' => 'boolean',
    ];

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(BusinessProfile::class, 'business_id');
    }

    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bot::class);
    }

    public function commerceConnection(): BelongsTo
    {
        return $this->belongsTo(Channel::class, 'commerce_connection_id');
    }
}
