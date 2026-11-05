<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VisitorConversion extends Model
{
    use HasFactory;

    public $timestamps = false;
    protected $table = 'visitor_conversions';

    protected $fillable = [
        'property_id',
        'session_id',
        'visitor_identity_id',
        'user_id',
        'goal_name',
        'revenue',
        'currency',
        'order_id',
        'metadata',
        'created_at',
    ];

    protected $casts = [
        'revenue' => 'decimal:2',
        'metadata' => 'array',
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
