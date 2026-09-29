<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessKnowledgeFile extends Model
{
    protected $fillable = [
        'business_profile_id',
        'filename',
        'file_type',
        'extracted_text',
        'status',
        'error_message',
        'file_size',
    ];

    protected $casts = [
        'uploaded_at' => 'datetime',
    ];

    public function businessProfile(): BelongsTo
    {
        return $this->belongsTo(BusinessProfile::class);
    }

    public function botAssignments()
    {
        return $this->hasMany(BotKnowledgeAssignment::class, 'business_knowledge_file_id');
    }

    public function bots()
    {
        return $this->belongsToMany(
            Bot::class,
            'bot_knowledge_assignments',
            'business_knowledge_file_id',
            'bot_id'
        )->withPivot('channel_id')->withTimestamps();
    }
}
