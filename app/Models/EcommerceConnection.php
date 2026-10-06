<?php

namespace App\Models;

/**
 * First-class view of the existing provider account records.
 *
 * Commerce credentials and provider metadata remain in the channels table so
 * this abstraction does not duplicate tokens or break existing OAuth flows.
 */
class EcommerceConnection extends Channel
{
    protected $table = 'channels';

    public static function providerTypes(): array
    {
        return ['salla', 'shopify', 'woocommerce'];
    }

    protected static function booted(): void
    {
        static::addGlobalScope('commerce_provider', function ($query): void {
            $query->whereIn('type', self::providerTypes());
        });
    }
}
