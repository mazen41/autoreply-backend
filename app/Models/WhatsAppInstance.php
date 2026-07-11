<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsAppInstance extends Model
{
    protected $fillable = [
        'user_id',
        'instance_name',
        'phone_number',
        'profile_name',
        'profile_picture_url',
        'status',
        'evolution_api_token',
        'evolution_instance_id',
        'webhook_url',
        'connected_at',
        'disconnected_at',
        'metadata',
    ];

    protected $casts = [
        'webhook_url' => 'array',
        'metadata' => 'json',
        'connected_at' => 'datetime',
        'disconnected_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(WhatsAppMessage::class);
    }

    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeConnected($query)
    {
        return $query->where('status', 'connected');
    }

    public function isConnected(): bool
    {
        return $this->status === 'connected';
    }

    public function isPending(): bool
    {
        return $this->status === 'pending' || $this->status === 'connecting';
    }
}
