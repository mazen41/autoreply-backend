<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CommerceOrder extends Model
{
    protected $fillable = [
        'business_id', 'channel_id', 'external_id', 'order_number', 'status',
        'fulfillment_status', 'total', 'currency', 'customer_name',
        'customer_email', 'customer_phone', 'shipping_address', 'line_items',
        'raw_data', 'ordered_at',
    ];

    protected $casts = [
        'line_items' => 'array',
        'raw_data' => 'array',
        'ordered_at' => 'datetime',
    ];
}
