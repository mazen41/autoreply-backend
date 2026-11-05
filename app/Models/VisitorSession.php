<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VisitorSession extends Model
{
    use HasFactory;

    protected $table = 'visitor_sessions';

    protected $fillable = [
        'session_id',
        'property_id',
        'visitor_identity_id',
        'user_id',
        'ip_hash',
        'country',
        'country_code',
        'city',
        'device_type',
        'browser',
        'os',
        'referrer',
        'referrer_domain',
        'entry_url',
        'exit_url',
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'utm_term',
        'utm_content',
        'pageviews_count',
        'events_count',
        'duration_seconds',
        'is_bounce',
        'started_at',
        'ended_at',
        'last_activity_at',
    ];

    protected $casts = [
        'is_bounce' => 'boolean',
        'pageviews_count' => 'integer',
        'events_count' => 'integer',
        'duration_seconds' => 'integer',
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'last_activity_at' => 'datetime',
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

    public function events(): HasMany
    {
        return $this->hasMany(VisitorEvent::class, 'session_id', 'session_id');
    }
}
