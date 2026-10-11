<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Deterministic mapping between an outgoing product-image WhatsApp message
 * and the Salla product it depicts. See CRITICAL ISSUE — REPLY-TO-PRODUCT
 * CONTEXT / PRODUCT SELECTION: when a customer replies to a specific
 * product image, this table is the single source of truth for resolving
 * exactly which product they mean — never AI inference.
 */
class ProductMessageMap extends Model
{
    protected $fillable = [
        'conversation_id',
        'channel_id',
        'provider',
        'commerce_provider',
        'platform_message_id',
        'recipient_id',
        'sent_at',
        'internal_message_id',
        'salla_product_id',
        'internal_product_id',
        'commerce_channel_id',
        'commerce_external_id',
        'sku',
        'product_name',
        'product_price',
        'currency',
        'image_url',
    ];

    public function conversation()
    {
        return $this->belongsTo(Conversation::class);
    }

    public function channel()
    {
        return $this->belongsTo(Channel::class);
    }
}
