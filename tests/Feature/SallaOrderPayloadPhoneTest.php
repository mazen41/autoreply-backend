<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\BusinessProfile;
use App\Models\User;
use App\Services\SallaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Exercises the real SallaService::createOrderForChannel path and captures the exact
 * JSON body that would be POSTed to /admin/v2/orders. Only the HTTP layer is faked;
 * no order is created anywhere.
 */
class SallaOrderPayloadPhoneTest extends TestCase
{
    use RefreshDatabase;

    private function channel(): Channel
    {
        $user = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $user->id]);

        $channel = Channel::factory()->create([
            'user_id' => $user->id,
            'business_id' => $business->id,
            'type' => 'salla',
            'status' => 'connected',
            'access_token' => 'test-token',
        ]);

        DB::table('salla_cities')->insert([
            'channel_id' => $channel->id,
            'country_code' => 'EG',
            'country_id' => 1723506348,
            'salla_city_id' => 552977415,
            'name' => 'الجيزة',
            'name_en' => 'Giza',
            'synced_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('salla_cities')->insert([
            'channel_id' => $channel->id,
            'country_code' => 'SA',
            'country_id' => 1473353380,
            'salla_city_id' => 1473353380,
            'name' => 'الرياض',
            'name_en' => 'Riyadh',
            'synced_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $channel;
    }

    private function state(string $phone): array
    {
        return [
            'phone' => $phone,
            'full_name' => 'Test Customer',
            'email' => 'customer@example.test',
            'address' => $phone === '+966505308084' ? '36 olaya street riyadh' : '36 sayed abdelrahman aladwey giza fisal',
            'postal_code' => '11511',
            'building_number' => '36',
            'salla_product_id' => '44563344',
            'product_name' => 'Test Product',
            'product_price' => 94,
            'product_currency' => 'SAR',
        ];
    }

    /** Fakes every HTTP call the order path makes, and records the POST /orders body. */
    private function fakeSalla(): void
    {
        Http::fake([
            'api.salla.dev/admin/v2/customers*' => function ($request) {
                return $request->method() === 'POST'
                    ? Http::response(['data' => ['id' => 7711]], 200)
                    : Http::response(['data' => []], 200);
            },
            'api.salla.dev/admin/v2/shipping/companies/estimate-rate*' => Http::response(['data' => [[
                'company_id' => 5,
                'total' => ['amount' => 20, 'currency' => 'SAR'],
                'services' => [['name' => 'cod', 'amount' => ['amount' => 0]]],
            ]]], 200),
            'api.salla.dev/admin/v2/orders' => Http::response(['data' => ['id' => 1, 'reference_id' => 'TEST-ONLY']], 200),
            'api.salla.dev/admin/v2/*' => Http::response(['data' => []], 200),
        ]);
    }

    private function sentOrderBody(): array
    {
        $body = null;
        Http::assertSent(function ($request) use (&$body) {
            if ($request->method() === 'POST' && str_contains($request->url(), '/admin/v2/orders') && !str_contains($request->url(), 'estimate')) {
                $body = $request->data();
                return true;
            }
            return false;
        });
        $this->assertNotNull($body, 'The real path did not POST to /admin/v2/orders');
        return $body;
    }

    /** @test */
    public function saudi_number_is_sent_as_documented_in_receiver_and_customer_fields(): void
    {
        $channel = $this->channel();
        $this->fakeSalla();

        app(SallaService::class)->createOrderForChannel($channel, $this->state('+966505308084'));

        $body = $this->sentOrderBody();
        $this->assertSame('966505308084', $body['receiver']['phone']);
        $this->assertSame('SA', $body['receiver']['country_code']);
        $this->assertSame('+966505308084', $body['customer']['mobile']);

        // ship_to must never contain an invented block — Salla rejects unlabelled text here.
        $this->assertArrayNotHasKey('block', $body['ship_to'] ?? [],
            'ship_to.block must be absent when the address has no labelled district/block keyword');
        $this->assertArrayHasKey('country', $body['ship_to']);
        $this->assertArrayHasKey('city', $body['ship_to']);
        $this->assertArrayHasKey('address', $body['ship_to']);
    }

    /** @test */
    public function egyptian_number_is_sent_with_the_egypt_calling_code(): void
    {
        $channel = $this->channel();
        $this->fakeSalla();

        app(SallaService::class)->createOrderForChannel($channel, $this->state('01152879755'));

        $body = $this->sentOrderBody();
        $this->assertSame('201152879755', $body['receiver']['phone']);
        $this->assertSame('EG', $body['receiver']['country_code']);
        $this->assertSame('+201152879755', $body['customer']['mobile']);

        // ship_to must never contain an invented block — Salla rejects unlabelled text here.
        $this->assertArrayNotHasKey('block', $body['ship_to'] ?? [],
            'ship_to.block must be absent when the address has no labelled district/block keyword');
        $this->assertArrayHasKey('country', $body['ship_to']);
        $this->assertArrayHasKey('city', $body['ship_to']);
        $this->assertArrayHasKey('address', $body['ship_to']);
    }

    /** @test */
    public function explicitly_supplied_block_in_checkout_data_is_preserved_in_ship_to(): void
    {
        $channel = $this->channel();
        $this->fakeSalla();

        $state = $this->state('+966505308084');
        $state['block'] = 'Block 12';

        app(SallaService::class)->createOrderForChannel($channel, $state);

        $body = $this->sentOrderBody();
        $this->assertSame('Block 12', $body['ship_to']['block'] ?? null,
            'Explicitly provided checkout block must be preserved in ship_to.block');
        $this->assertSame('36', $body['ship_to']['building_number'] ?? null);
        $this->assertSame('11511', $body['ship_to']['postal_code'] ?? null);
    }
}