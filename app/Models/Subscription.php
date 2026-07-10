<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Subscription extends Model
{
    protected $fillable = [
        'user_id',
        'package_id',
        'status',
        'billing_cycle',
        'amount_paid',
        'moyasar_payment_id',
        'moyasar_invoice_id',
        'starts_at',
        'ends_at',
        'cancelled_at',
        'trial_ends_at',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'trial_ends_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function package()
    {
        return $this->belongsTo(Package::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active')
            ->where('starts_at', '<=', now())
            ->where('ends_at', '>', now());
    }

    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function isActive()
    {
        return $this->status === 'active' 
            && $this->starts_at <= now() 
            && $this->ends_at > now();
    }

    public function isExpired()
    {
        return $this->status === 'expired' || $this->ends_at < now();
    }
}
