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
    ];

    protected $casts = [
        'uploaded_at' => 'datetime',
    ];

    public function businessProfile(): BelongsTo
    {
        return $this->belongsTo(BusinessProfile::class);
    }
}
