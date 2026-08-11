<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Overage extends Model
{
    protected $fillable = [
        'user_id',
        'subscription_id',
        'extra_messages',
        'amount',
        'status',
        'billed_at',
    ];

    protected $casts = [
        'billed_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function subscription()
    {
        return $this->belongsTo(Subscription::class);
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeBilled($query)
    {
        return $query->where('status', 'billed');
    }

    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }
}
