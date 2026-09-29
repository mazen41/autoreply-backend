<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Bot extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_profile_id',
        'ecommerce_channel_id',
        'name',
        'status',
        'ai_provider',
        'ai_model',
        'ai_instructions',
        'ai_tone_style',
        'reply_style',
        'ai_confidence_threshold',
        'escalation_config',
    ];

    protected $casts = [
        'ai_tone_style' => 'array',
        'escalation_config' => 'array',
        'ai_confidence_threshold' => 'float',
    ];

    public function businessProfile(): BelongsTo
    {
        return $this->belongsTo(BusinessProfile::class, 'business_profile_id');
    }

    public function ecommerceChannel(): BelongsTo
    {
        return $this->belongsTo(Channel::class, 'ecommerce_channel_id');
    }

    public function channels(): BelongsToMany
    {
        return $this->belongsToMany(Channel::class, 'bot_channels', 'bot_id', 'channel_id')
            ->withPivot('is_primary')
            ->withTimestamps();
    }

    public function knowledgeAssignments(): HasMany
    {
        return $this->hasMany(BotKnowledgeAssignment::class, 'bot_id');
    }

    public function knowledgeFiles(): BelongsToMany
    {
        return $this->belongsToMany(
            BusinessKnowledgeFile::class,
            'bot_knowledge_assignments',
            'bot_id',
            'business_knowledge_file_id'
        )->withPivot('channel_id')->withTimestamps();
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class, 'bot_id');
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}
