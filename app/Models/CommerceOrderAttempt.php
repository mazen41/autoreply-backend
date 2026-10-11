<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CommerceOrderAttempt extends Model
{
    protected $fillable = [
        'business_id',
        'conversation_id',
        'connection_id',
        'provider',
        'checkout_fingerprint',
        'idempotency_key',
        'status',
        'external_order_id',
        'order_number',
    ];
}
