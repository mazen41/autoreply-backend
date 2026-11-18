<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SallaWebhookReceipt extends Model
{
    protected $fillable = ['event_key', 'event', 'status', 'attempts', 'processed_at'];

    protected $casts = ['processed_at' => 'datetime'];
}
