<?php

namespace Tests\Feature;

use App\Models\BusinessProfile;
use App\Models\Channel;
use App\Models\User;
use App\Services\WooCommerceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WooCommerceCurrencyTest extends TestCase
{
    use RefreshDatabase;

    private const STORE = 'https://shop.test';

    private function wooChannel(): Channel
    {
        $user = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $user->id]);

        return Channel::factory()->create([
            'user_id' => $user->id,
            'business_id' => $business->id,
            'type' => 'woocommerce',
            'page_id' => self::STORE,
            'status' => 'connected',
            'metadata' => [
                'store_url' => self::STORE,
                'consumer_key' => encrypt('ck_test'),
                'consumer_secret' => encrypt('cs_test'),
            ],
        ]);
    }

    private function checkout(): array
    {
        return [
            'commerce_external_id' => '123',
            'full_name' => 'Sara Hassan',
            'phone' => '+201001234567',
            'address' => '12 Tahrir Street',
            'quantity' => 1,
            'commerce_product_metadata' => ['type' => 'simple'],
        ];
    }

    public function test_order_is_created_in_the_store_currency(): void
    {
        Http::fake([
            self::STORE . '/wp-json/wc/v3/data/currencies/current' => Http::response(['code' => 'EGP', 'symbol' => 'E£'], 200),
            self::STORE . '/wp-json/wc/v3/orders' => Http::response(['id' => 10, 'number' => '10', 'status' => 'pending', 'total' => '100.00', 'currency' => 'EGP', 'line_items' => []], 201),
        ]);

        (new WooCommerceService())->createOrderForChannel($this->wooChannel(), $this->checkout());

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/wp-json/wc/v3/orders')
            && ($request->data()['currency'] ?? null) === 'EGP');
    }

    public function test_order_is_not_created_when_the_store_currency_cannot_be_read(): void
    {
        Http::fake([
            self::STORE . '/wp-json/wc/v3/data/currencies/current' => Http::response(['message' => 'nope'], 500),
            self::STORE . '/wp-json/wc/v3/orders' => Http::response(['id' => 10], 201),
        ]);

        $this->expectException(\RuntimeException::class);

        try {
            (new WooCommerceService())->createOrderForChannel($this->wooChannel(), $this->checkout());
        } finally {
            Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/wp-json/wc/v3/orders'));
        }
    }
}