<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessProfile extends Model
{
    protected $fillable = [
        'user_id',
        'business_type',
        'business_name',
        'phone',
        'city',
        'country',
        'working_days',
        'working_from',
        'working_to',
        'services',
        'faqs',
        'reply_style',
        'connected_channel',
        'ai_instructions',
        'ai_confidence_threshold',
        'ai_tone_style',
        'business_hours_enabled',
        'after_hours_message',
        'ai_provider',
        'ai_model',
    ];

    protected $casts = [
        'working_days' => 'array',
        'faqs'         => 'array',
        'ai_tone_style' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function knowledgeFiles()
    {
        return $this->hasMany(\App\Models\BusinessKnowledgeFile::class);
    }
}
