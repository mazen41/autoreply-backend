<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Channel extends Model
{
    protected $fillable = [
        'user_id',
        'business_id',
        'type',
        'page_id',
        'page_name',
        'instagram_account_id',
        'access_token',
        'refresh_token',
        'status',
        'connected_at',
        'ai_enabled',
    ];

    protected $casts = [
        'connected_at' => 'datetime',
    ];

    public function setAccessTokenAttribute($value)
    {
        $this->attributes['access_token'] = encrypt($value);
    }

    public function getAccessTokenAttribute($value)
    {
        return decrypt($value);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function business()
    {
        return $this->belongsTo(BusinessProfile::class, 'business_id');
    }

    public function conversations()
    {
        return $this->hasMany(Conversation::class);
    }
}
