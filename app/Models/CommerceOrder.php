<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class, 'channel_id');
    }
}
