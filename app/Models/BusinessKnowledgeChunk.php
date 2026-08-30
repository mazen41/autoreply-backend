<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessKnowledgeChunk extends Model
{
    protected $fillable = [
        'business_knowledge_file_id',
        'business_profile_id',
        'chunk_index',
        'content',
        'embedding',
    ];

    protected $casts = [
        'embedding' => 'array',
    ];

    public function file(): BelongsTo
    {
        return $this->belongsTo(BusinessKnowledgeFile::class, 'business_knowledge_file_id');
    }

    public function businessProfile(): BelongsTo
    {
        return $this->belongsTo(BusinessProfile::class, 'business_profile_id');
    }
}
