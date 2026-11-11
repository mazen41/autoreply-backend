<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CentralPaymentEvent extends Model
{
    protected $connection = 'central_payments';

    protected $fillable = [
        'central_payment_id', 'event_reference', 'event_type', 'payload',
        'processing_status', 'failure_reason', 'processed_at',
    ];

    protected function casts(): array
    {
        return ['payload' => 'array', 'processed_at' => 'datetime'];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(CentralPayment::class, 'central_payment_id');
    }
}
