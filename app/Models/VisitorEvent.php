<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VisitorEvent extends Model
{
    use HasFactory;

    public $timestamps = false;
    protected $table = 'visitor_events';

    protected $fillable = [
        'property_id',
        'session_id',
        'visitor_identity_id',
        'user_id',
        'event_type',
        'event_name',
        'category',
        'url',
        'path',
        'page_title',
        'properties',
        'created_at',
    ];

    protected $casts = [
        'properties' => 'array',
        'created_at' => 'datetime',
    ];

    public function property(): BelongsTo
    {
        return $this->belongsTo(VisitorProperty::class, 'property_id');
    }

    public function identity(): BelongsTo
    {
        return $this->belongsTo(VisitorIdentity::class, 'visitor_identity_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
