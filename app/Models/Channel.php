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
        // For WhatsApp, we store empty string (instance_name is in page_id)
        if (empty($value)) {
            $this->attributes['access_token'] = '';
        } else {
            $this->attributes['access_token'] = encrypt($value);
        }
    }

    public function getAccessTokenAttribute($value)
    {
        // For WhatsApp, we store empty string in access_token (instance_name is in page_id)
        if (empty($value)) {
            return null;
        }

        try {
            return decrypt($value);
        } catch (\Exception $e) {
            // If decryption fails, return the value as-is (might be already decrypted)
            return $value;
        }
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
