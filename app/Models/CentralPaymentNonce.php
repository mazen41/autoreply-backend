<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CentralPaymentNonce extends Model
{
    protected $connection = 'central_payments';

    protected $fillable = ['payment_client_id', 'nonce_hash', 'expires_at'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }
}
