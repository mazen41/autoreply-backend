<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TeamInvitation extends Model
{
    protected $fillable = [
        'business_id', 'invited_by', 'email', 'role', 'token_hash', 'expires_at', 'status', 'accepted_at',
    ];

    protected $hidden = ['token_hash', 'delivery_token'];

    protected $casts = [
        'expires_at' => 'datetime',
        'accepted_at' => 'datetime',
    ];

    public function business()
    {
        return $this->belongsTo(BusinessProfile::class);
    }
}
