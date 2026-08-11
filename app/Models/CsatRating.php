<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CsatRating extends Model
{
    protected $fillable = [
        'business_id',
        'conversation_id',
        'user_id',
        'rating',
        'feedback',
        'rated_at',
    ];

    protected $casts = [
        'rated_at' => 'datetime',
    ];

    public function business()
    {
        return $this->belongsTo(BusinessProfile::class, 'business_id');
    }

    public function conversation()
    {
        return $this->belongsTo(Conversation::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function scopePositive($query)
    {
        return $query->where('rating', 'positive');
    }

    public function scopeNegative($query)
    {
        return $query->where('rating', 'negative');
    }
}
