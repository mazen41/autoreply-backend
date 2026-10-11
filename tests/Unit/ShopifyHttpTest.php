<?php

namespace Tests\Unit;

use App\Services\ShopifyHttp;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShopifyHttpTest extends TestCase
{
    public function test_shopify_requests_send_the_access_token_header_and_no_bearer_authorization(): void
    {
        Http::fake();

        ShopifyHttp::client('shpua_test_token')->get('https://shop.myshopify.com/admin/api/2026-07/shop.json');

        Http::assertSent(function ($request) {
            return $request->hasHeader('X-Shopify-Access-Token', 'shpua_test_token')
                && !$request->hasHeader('Authorization');
        });
    }
}