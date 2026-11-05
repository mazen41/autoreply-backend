<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiCreditWallet extends Model
{
    protected $guarded = [];

    protected $casts = [
        'subscription_balance' => 'integer',
        'purchased_balance' => 'integer',
        'reserved_balance' => 'integer',
        'total_consumed' => 'integer',
        'billing_period_ends_at' => 'datetime',
        'notified_thresholds' => 'array',
    ];

    public function business() { return $this->belongsTo(BusinessProfile::class); }
    public function transactions() { return $this->hasMany(AiCreditTransaction::class, 'wallet_id'); }
}
