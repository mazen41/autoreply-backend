<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VisitorIdentity extends Model
{
    use HasFactory;

    protected $table = 'visitor_identities';

    protected $fillable = [
        'property_id',
        'anonymous_id',
        'user_id',
        'first_seen_at',
        'last_seen_at',
        'traits',
    ];

    protected $casts = [
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'traits' => 'array',
    ];

    public function property(): BelongsTo
    {
        return $this->belongsTo(VisitorProperty::class, 'property_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(VisitorSession::class, 'visitor_identity_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(VisitorEvent::class, 'visitor_identity_id');
    }
}
