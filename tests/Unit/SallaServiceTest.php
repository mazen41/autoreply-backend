<?php

namespace Tests\Unit;

use App\Models\Channel;
use App\Services\SallaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SallaServiceTest extends TestCase
{
    use RefreshDatabase;

    private SallaService $sallaService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sallaService = new SallaService();
    }

    public function test_get_authorization_url(): void
    {
        config([
            'services.salla.client_id' => 'test_client_id',
            'services.salla.redirect_uri' => 'https://test.com/callback',
        ]);

        $url = $this->sallaService->getAuthorizationUrl('test_state');

        $this->assertStringContainsString('accounts.salla.sa/oauth2/auth', $url);
        $this->assertStringContainsString('client_id=test_client_id', $url);
        $this->assertStringContainsString('state=test_state', $url);
        $this->assertStringContainsString('response_type=code', $url);
        $this->assertStringContainsString('metadata.read', $url);
    }

    public function test_verify_webhook_signature_valid(): void
    {
        config(['services.salla.webhook_secret' => 'test_secret']);

        $payload = 'test_payload';
        $signature = hash_hmac('sha256', $payload, 'test_secret');

        $result = $this->sallaService->verifyWebhookSignature($payload, $signature);

        $this->assertTrue($result);
    }

    public function test_verify_webhook_signature_invalid(): void
    {
        config(['services.salla.webhook_secret' => 'test_secret']);

        $payload = 'test_payload';
        $invalidSignature = 'invalid_signature';

        $result = $this->sallaService->verifyWebhookSignature($payload, $invalidSignature);

        $this->assertFalse($result);
    }

    public function test_format_order_for_ai(): void
    {
        $order = [
            'reference_id' => 'ORD-123',
            'status' => ['name' => 'processing'],
            'total' => ['amount' => '150.00', 'currency' => 'SAR'],
            'items' => [
                ['product' => ['name' => 'Product A'], 'quantity' => 2],
                ['product' => ['name' => 'Product B'], 'quantity' => 1],
            ],
            'shipping' => [
                'status' => ['name' => 'shipped'],
                'estimated_delivery' => '2024-12-01',
            ],
        ];

        $formatted = $this->sallaService->formatOrderForAI($order);

        $this->assertStringContainsString('ORD-123', $formatted);
        $this->assertStringContainsString('processing', $formatted);
        $this->assertStringContainsString('150.00 SAR', $formatted);
        $this->assertStringContainsString('Product A x2', $formatted);
        $this->assertStringContainsString('Product B x1', $formatted);
        $this->assertStringContainsString('shipped', $formatted);
        $this->assertStringContainsString('2024-12-01', $formatted);
    }

    public function test_unknown_phone_and_address_country_do_not_default_shipping_to_saudi(): void
    {
        $channel = Channel::factory()->create(['type' => 'salla', 'status' => 'connected']);
        $this->seedSharedCityCatalog('SA', 1473353380, [
            ['id' => 4, 'name' => 'Riyadh', 'name_en' => 'Riyadh'],
        ]);

        $unknown = $this->sallaService->resolveShippingAddressForChannel($channel, 'Riyadh, Main Street', '1234567890');
        $explicitSaudi = $this->sallaService->resolveShippingAddressForChannel($channel, 'Riyadh, Saudi Arabia', '1234567890');

        $this->assertNull($unknown);
        $this->assertSame(4, $explicitSaudi['city_id'] ?? null);
    }

    public function test_missing_scope_does_not_refresh_or_mark_channel_token_expired(): void
    {
        $channel = Channel::factory()->create([
            'type' => 'salla',
            'status' => 'connected',
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
        ]);

        Http::fake([
            'https://api.salla.dev/admin/v2/countries' => Http::response([
                'status' => 401,
                'success' => false,
                'error' => [
                    'code' => 'Unauthorized',
                    'message' => 'The access token should have access to one of those scopes: metadata.read,metadata.read_write',
                ],
            ], 401),
            '*' => Http::response([], 500),
        ]);

        $service = new class extends SallaService {
            public function callForChannel(Channel $channel): array
            {
                return $this->apiCallForChannel($channel, 'GET', '/countries');
            }
        };

        try {
            $service->callForChannel($channel);
            $this->fail('Expected missing-scope exception was not thrown.');
        } catch (\Exception $exception) {
            $this->assertStringContainsString('Salla API scope missing', $exception->getMessage());
        }

        $this->assertSame('connected', $channel->fresh()->status);
        Http::assertSentCount(1);
    }

    public function test_missing_scope_after_token_refresh_preserves_refreshed_credentials_and_channel_status(): void
    {
        $channel = Channel::factory()->create([
            'type' => 'salla',
            'status' => 'connected',
            'access_token' => 'old-access-token',
            'refresh_token' => 'old-refresh-token',
        ]);

        $scopeDenied = [
            'status' => 401,
            'success' => false,
            'error' => [
                'code' => 'Unauthorized',
                'message' => 'The access token should have access to one of those scopes: metadata.read,metadata.read_write',
            ],
        ];

        Http::fake([
            'https://api.salla.dev/admin/v2/countries' => Http::sequence()
                ->push(['error' => ['message' => 'Access token expired']], 401)
                ->push($scopeDenied, 401),
            'https://accounts.salla.sa/oauth2/token' => Http::response([
                'access_token' => 'new-access-token',
                'refresh_token' => 'new-refresh-token',
                'expires_in' => 3600,
            ], 200),
        ]);

        $service = new class extends SallaService {
            public function callForChannel(Channel $channel): array
            {
                return $this->apiCallForChannel($channel, 'GET', '/countries');
            }
        };

        try {
            $service->callForChannel($channel);
            $this->fail('Expected missing-scope exception was not thrown.');
        } catch (\Exception $exception) {
            $this->assertStringContainsString('Salla API scope missing', $exception->getMessage());
        }

        Http::assertSentCount(3);
        $channel->refresh();
        $this->assertSame('connected', $channel->status);
        $this->assertSame('new-access-token', $channel->access_token);
        $this->assertSame('new-refresh-token', $channel->refresh_token);
    }

    public function test_salla_api_response_is_used_when_cache_write_fails(): void
    {
        Cache::shouldReceive('get')->once()->andReturn(null);
        Cache::shouldReceive('put')->once()->andThrow(new \RuntimeException('cache shard directory missing'));

        $service = new class extends SallaService {
            public function cachedResponse(callable $fetch): array
            {
                return $this->cachedSallaResponse('salla_test_key', '/countries', $fetch);
            }
        };

        $apiResponse = ['data' => [['id' => 123, 'code' => 'EG']]];
        $response = $service->cachedResponse(fn () => $apiResponse);

        $this->assertSame($apiResponse, $response);
    }

    public function test_shipping_city_lookup_checks_later_salla_pagination_pages(): void
    {
        $channel = Channel::factory()->create([
            'type' => 'salla',
            'status' => 'connected',
            'access_token' => 'access-token',
            'user_id' => \App\Models\User::factory()->create()->id,
        ]);

        Http::fake([
            'https://api.salla.dev/admin/v2/countries' => Http::response([
                'data' => [['id' => 1723506348, 'code' => 'EG']],
            ], 200),
            'https://api.salla.dev/admin/v2/countries/1723506348/cities*' => Http::sequence()
                ->push([
                    'data' => [['id' => 1, 'name' => 'Cairo', 'name_en' => 'Cairo']],
                    'pagination' => [
                        'total' => 2,
                        'totalPages' => 2,
                        'currentPage' => 1,
                        'links' => ['next' => 'https://api.salla.dev/admin/v2/countries/1723506348/cities?page=2'],
                    ],
                ], 200)
                ->push([
                    'data' => [['id' => 778, 'name' => 'Giza', 'name_en' => 'Giza']],
                    'pagination' => ['total' => 2, 'totalPages' => 2, 'currentPage' => 2, 'links' => []],
                ], 200),
        ]);

        $this->sallaService->syncCityCatalog($channel, 'EG');

        $shippingAddress = $this->sallaService->resolveShippingAddressForChannel(
            $channel,
            '36 Sayed Abdelrahman Mohamed, Giza Faisal Street',
            '01152879755'
        );

        $this->assertSame(778, $shippingAddress['city_id'] ?? null);
        Http::assertSentCount(3);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'cities?page=2'));
        Http::assertSent(fn ($request) =>
            str_contains($request->url(), '/cities')
            && str_contains($request->url(), 'per_page=15')
            && str_contains($request->url(), 'count=15')
        );
    }

    public function test_failed_city_sync_preserves_the_last_successful_catalog(): void
    {
        $channel = Channel::factory()->create([
            'type' => 'salla', 'status' => 'connected', 'access_token' => 'access-token',
            'user_id' => \App\Models\User::factory()->create()->id,
        ]);
        $this->seedSharedCityCatalog('SA', 55, [
            ['id' => 99, 'name' => 'Existing City', 'name_en' => 'Existing City'],
        ], 'failed');

        Http::fake([
            'https://api.salla.dev/admin/v2/countries' => Http::response([
                'data' => [['id' => 55, 'code' => 'SA']],
            ], 200),
            'https://api.salla.dev/admin/v2/countries/55/cities*' => Http::response([
                'data' => [['id' => 100, 'name' => 'New City', 'name_en' => 'New City']],
                'pagination' => ['totalPages' => 1, 'currentPage' => 1],
            ], 200),
        ]);

        try {
            $this->sallaService->syncCityCatalog($channel, 'SA');
            $this->fail('The invalid pagination response should fail the sync.');
        } catch (\UnexpectedValueException) {
            $this->assertDatabaseHas('salla_city_reference_cities', [
                'country_id' => 55, 'salla_city_id' => 99,
            ]);
            $this->assertDatabaseMissing('salla_city_reference_cities', [
                'country_id' => 55, 'salla_city_id' => 100,
            ]);
            $this->assertDatabaseHas('salla_city_catalogs', [
                'country_code' => 'SA', 'status' => 'failed',
                'saved_city_count' => 1,
            ]);
        }
    }

    public function test_city_sync_fails_closed_when_metadata_scope_is_known_missing(): void
    {
        $channel = Channel::factory()->create([
            'type' => 'salla', 'status' => 'connected', 'access_token' => 'access-token',
            'user_id' => \App\Models\User::factory()->create()->id,
            'metadata' => ['scopes' => 'orders.read_write'],
        ]);
        Http::preventStrayRequests();

        try {
            $this->sallaService->syncCityCatalog($channel, 'SA');
            $this->fail('Missing metadata.read must block sync.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('metadata.read', $exception->getMessage());
            $this->assertDatabaseHas('salla_city_catalogs', [
                'country_code' => 'SA', 'status' => 'failed',
            ]);
        }
    }

    public function test_build_canonical_order_payload_formats_phone_fields_correctly(): void
    {
        $channel = Channel::factory()->create([
            'type' => 'salla',
            'status' => 'connected',
            'access_token' => 'test-token',
        ]);

        $this->seedSharedCityCatalog('SA', 1473353380, [
            ['id' => 4, 'name' => 'Riyadh', 'name_en' => 'Riyadh'],
        ]);

        Http::fake([
            'https://api.salla.dev/admin/v2/customers*' => Http::response([
                'data' => [
                    [
                        'id' => 12345,
                        'name' => 'Test Customer',
                        'mobile' => '+966558912437',
                        'email' => 'receiver@example.com',
                    ],
                ],
            ], 200),
            'https://api.salla.dev/admin/v2/shipping/companies/estimate-rate*' => Http::response([
                'data' => [
                    [
                        'company_id' => 326899803,
                        'total' => ['amount' => 24, 'currency' => 'SAR'],
                        'services' => [
                            ['name' => 'cod', 'amount' => ['amount' => 0]],
                        ],
                    ],
                ],
            ], 200),
            'https://api.salla.dev/admin/v2/cities/4/districts*' => Http::response([
                'data' => [['id' => 99, 'name' => 'حي العليا', 'name_en' => 'Al Olaya Dist.']],
                'pagination' => ['totalPages' => 1],
            ], 200),
        ]);

        $checkoutState = [
            'phone' => '+966 55 891 2437',
            'full_name' => 'Test Customer',
            'email' => 'receiver@example.com',
            'address' => 'King Fahd Road, Riyadh',
            'postal_code' => '12211',
            'building_number' => '8230',
            'street_number' => '8230',
            'block' => 'Second floor',
            'district' => 'Al Olaya',
            'short_address' => 'RRRR1234',
            'additional_number' => '5678',
            'geo_coordinates' => ['lat' => 24.7136, 'lng' => 46.6753],
            'salla_product_id' => 1242785692,
            'product_price' => 200,
        ];

        $payload = $this->sallaService->buildCanonicalOrderPayload($channel, $checkoutState);

        $this->assertNotNull($payload);

        // Salla Support confirmed this store rejects receiver.phone without '+'.
        $this->assertSame('+966558912437', $payload['receiver']['phone']);
        $this->assertSame('SA', $payload['receiver']['country_code']);
        $this->assertFalse($payload['receiver']['notify']);
        $this->assertSame('Test Customer', $payload['receiver']['name']);
        $this->assertSame('receiver@example.com', $payload['receiver']['email']);

        // customer.mobile: separate field WITH leading '+'
        $this->assertSame('+966558912437', $payload['customer']['mobile']);
        $this->assertSame('Test Customer', $payload['customer']['name']);
        $this->assertSame('receiver@example.com', $payload['customer']['email']);
        $this->assertSame(12345, $payload['customer']['id']);

        // Unrelated fields preserved without modification
        $this->assertSame('shipping', $payload['delivery_method']);
        $this->assertSame(326899803, $payload['courier_id']);
        $this->assertSame(1242785692, $payload['products'][0]['identifier']);
        $this->assertSame('id', $payload['products'][0]['identifier_type']);
        $this->assertSame(1, $payload['products'][0]['quantity']);
        $this->assertSame('cod', $payload['payment']['method']);
        $this->assertSame('pending_payment', $payload['payment']['status']);
        $this->assertSame(224.0, $payload['payment']['cash_on_delivery']['amount']);
        $this->assertSame('SAR', $payload['payment']['cash_on_delivery']['currency']);
        $this->assertSame(4, $payload['ship_to']['city']);
        $this->assertSame(1473353380, $payload['ship_to']['country']);
        $this->assertSame('12211', $payload['ship_to']['postal_code']);
        $this->assertSame(99, $payload['ship_to']['district']);
    }

    public function test_build_canonical_order_payload_rejects_repeated_digit_placeholder_phone(): void
    {
        $channel = Channel::factory()->create([
            'type' => 'salla',
            'status' => 'connected',
            'access_token' => 'test-token',
        ]);

        $this->seedSharedCityCatalog('SA', 1473353380, [
            ['id' => 4, 'name' => 'Riyadh', 'name_en' => 'Riyadh'],
        ]);

        Http::fake([
            'https://api.salla.dev/admin/v2/customers*' => Http::response([
                'data' => [
                    [
                        'id' => 12345,
                        'name' => 'Test Customer',
                        'mobile' => '+966566666666',
                        'email' => 'receiver@example.com',
                    ],
                ],
            ], 200),
            'https://api.salla.dev/admin/v2/shipping/companies/estimate-rate*' => Http::response([
                'data' => [
                    [
                        'company_id' => 326899803,
                        'total' => ['amount' => 24, 'currency' => 'SAR'],
                        'services' => [
                            ['name' => 'cod', 'amount' => ['amount' => 0]],
                        ],
                    ],
                ],
            ], 200),
        ]);

        // Salla documentation placeholder with repeated 6s
        $checkoutState = [
            'phone' => '+966566666666',
            'full_name' => 'Test Customer',
            'email' => 'receiver@example.com',
            'address' => 'King Fahd Road, Riyadh',
            'postal_code' => '12211',
            'salla_product_id' => 1242785692,
            'product_price' => 200,
        ];

        $payload = $this->sallaService->buildCanonicalOrderPayload($channel, $checkoutState);

        // Must reject placeholder phone before creating an invalid order in Salla
        $this->assertNull($payload);
        Http::assertNotSent(fn ($request) =>
            $request->method() === 'POST'
            && str_contains($request->url(), '/admin/v2/customers')
        );
    }

    public function test_build_canonical_order_payload_preserves_non_saudi_market_country_code(): void
    {
        $channel = Channel::factory()->create([
            'type' => 'salla',
            'status' => 'connected',
            'access_token' => 'test-token',
        ]);

        $this->seedSharedCityCatalog('EG', 1723506348, [
            ['id' => 778, 'name' => 'Giza', 'name_en' => 'Giza'],
        ]);

        Http::fake([
            'https://api.salla.dev/admin/v2/customers*' => Http::response([
                'data' => [
                    [
                        'id' => 67890,
                        'name' => 'Egyptian Customer',
                        'mobile' => '+201001234567',
                        'email' => 'eg-customer@example.com',
                    ],
                ],
            ], 200),
            'https://api.salla.dev/admin/v2/shipping/companies/estimate-rate*' => Http::response([
                'data' => [
                    [
                        'company_id' => 326899803,
                        'total' => ['amount' => 50, 'currency' => 'SAR'],
                        'services' => [
                            ['name' => 'cod', 'amount' => ['amount' => 0]],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $checkoutState = [
            'phone' => '01001234567', // Egyptian local format
            'full_name' => 'Egyptian Customer',
            'email' => 'eg-customer@example.com',
            'address' => 'Faisal Street, Giza, Egypt',
            'postal_code' => '12511',
            'building_number' => '15',
            'block' => 'Faisal',
            'geo_coordinates' => ['lat' => 30.0131, 'lng' => 31.2089],
            'salla_product_id' => 1242785692,
            'product_price' => 150,
        ];

        $payload = $this->sallaService->buildCanonicalOrderPayload($channel, $checkoutState);

        $this->assertNotNull($payload);
        $this->assertSame('EG', $payload['receiver']['country_code']);
        $this->assertSame('+201001234567', $payload['receiver']['phone']);
        $this->assertSame('+201001234567', $payload['customer']['mobile']);
    }

    private function seedSharedCityCatalog(string $countryCode, int $countryId, array $cities, string $status = 'succeeded'): void
    {
        $now = now();
        foreach ($cities as $city) {
            $name = (string) ($city['name'] ?? '');
            $nameEn = (string) ($city['name_en'] ?? '');
            \Illuminate\Support\Facades\DB::table('salla_city_reference_cities')->insert([
                'country_id' => $countryId,
                'salla_city_id' => $city['id'],
                'name' => $name,
                'name_en' => $nameEn !== '' ? $nameEn : null,
                'name_ar' => preg_match('/[\x{0600}-\x{06FF}]/u', $name) ? $name : null,
                'normalized_name' => $this->sallaService->normalizeCitySearchText($name),
                'normalized_en' => $nameEn !== '' ? $this->sallaService->normalizeCitySearchText($nameEn) : null,
                'normalized_ar' => preg_match('/[\x{0600}-\x{06FF}]/u', $name) ? $this->sallaService->normalizeCitySearchText($name) : null,
                'synced_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        \App\Models\SallaCityCatalog::updateOrCreate(
            ['country_code' => $countryCode],
            [
                'country_id' => $countryId,
                'status' => $status,
                'pages_completed' => 1,
                'total_pages' => 1,
                'expected_city_count' => count($cities),
                'staged_city_count' => count($cities),
                'saved_city_count' => count($cities),
                'synced_at' => $now,
            ]
        );
    }
}
