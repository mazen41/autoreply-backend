<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Subscription extends Model
{
    protected $hidden = ['myfatoorah_recurring_id'];

    protected $fillable = [
        'user_id',
        'business_id',
        'package_id',
        'status',
        'billing_cycle',
        'amount_paid',
        'paymob_order_id',
        'paymob_transaction_id',
        'myfatoorah_invoice_id',
        'myfatoorah_payment_id',
        'myfatoorah_recurring_id',
        'auto_renew_enabled',
        'auto_renew_consented_at',
        'payment_gateway',
        'starts_at',
        'ends_at',
        'cancelled_at',
        'trial_ends_at',
        'grace_period_ends_at',
        'usage_count',
        'usage_limit',
        'last_usage_alert_at',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'trial_ends_at' => 'datetime',
        'grace_period_ends_at' => 'datetime',
        'last_usage_alert_at' => 'datetime',
        'auto_renew_enabled' => 'boolean',
        'auto_renew_consented_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function business()
    {
        return $this->belongsTo(BusinessProfile::class, 'business_id');
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
