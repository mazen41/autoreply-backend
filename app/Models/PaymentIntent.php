<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentIntent extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'business_id',
        'package_id',
        'billing_cycle',
        'status',
        'expected_amount_minor',
        'expected_currency',
        'auto_renew_requested',
        'auto_renew_consented_at',
        'myfatoorah_recurring_id',
        'failure_reason',
        'paymob_order_id',
        'paymob_transaction_id',
        'myfatoorah_invoice_id',
        'myfatoorah_payment_id',
        'payment_gateway',
        'gateway_response',
    ];

    protected $casts = [
        'gateway_response' => 'array',
        'expected_amount_minor' => 'integer',
        'auto_renew_requested' => 'boolean',
        'auto_renew_consented_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }
}
