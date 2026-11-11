<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CentralPayment extends Model
{
    protected $connection = 'central_payments';

    protected $fillable = [
        'uuid', 'payment_client_id', 'external_order_id', 'external_customer_id',
        'external_package_id', 'package_name', 'customer_email', 'customer_name',
        'amount', 'currency', 'status', 'idempotency_key', 'myfatoorah_invoice_id',
        'myfatoorah_payment_id', 'checkout_url', 'customer_details', 'verified_order',
        'gateway_metadata', 'expires_at', 'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'customer_details' => 'array',
            'verified_order' => 'array',
            'gateway_metadata' => 'array',
            'expires_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(PaymentClient::class, 'payment_client_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(CentralPaymentEvent::class);
    }
}
