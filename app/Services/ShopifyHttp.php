<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

/**
 * Shopify's Admin REST API authenticates with the X-Shopify-Access-Token header.
 * Bearer authorization (Http::withToken) is rejected with 401 by Shopify.
 */
class ShopifyHttp
{
    public static function client(?string $accessToken): \Illuminate\Http\Client\PendingRequest
    {
        return Http::withHeaders(['X-Shopify-Access-Token' => (string) ($accessToken ?? '')])
            ->acceptJson();
    }
}