<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HelpCenterArticle extends Model
{
    protected $fillable = ['business_id', 'created_by', 'title', 'slug', 'category', 'excerpt', 'content', 'status', 'published_at'];
    protected $casts = ['published_at' => 'datetime'];

    public function business(): BelongsTo { return $this->belongsTo(BusinessProfile::class); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
}
