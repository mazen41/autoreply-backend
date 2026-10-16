<?php

namespace Tests\Feature;

use App\Models\BusinessProfile;
use App\Models\Channel;
use App\Models\User;
use App\Services\WooCommerceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Regression tests for WooCommerce order creation HTTP 400 rejection.
 *
 * Root cause: billing.email was sent as an empty string "" when no email was
 * collected.  WooCommerce validates billing.email strictly — an empty string
 * is not a valid email and not treated as "absent", so it rejects the whole
 * payload with HTTP 400 and code "rest_invalid_param".
 *
 * Additionally, payment_method and payment_method_title were sent as empty
 * strings, which some WooCommerce configurations reject.
 *
 * The fix omits optional billing fields (email, phone) when they are empty
 * and removes the empty payment_method fields.
 */
class WooCommerceOrderValidationTest extends TestCase
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

    /** Checkout data that mirrors the production scenario: no email collected. */
    private function checkoutWithoutEmail(): array
    {
        return [
            'commerce_external_id' => '18',
            'full_name' => 'Ahmed Ali',
            'phone' => '+201001234567',
            'address' => '12 Tahrir Street, Cairo',
            'quantity' => 1,
            'commerce_product_metadata' => ['type' => 'simple'],
        ];
    }

    /**
     * Reproduces the original bug: the old code sent billing.email as ""
     * which WooCommerce rejects with HTTP 400.  After the fix, the empty
     * email is omitted and the order succeeds.
     */
    public function test_order_without_email_omits_billing_email_from_payload(): void
    {
        Http::fake([
            self::STORE . '/wp-json/wc/v3/data/currencies/current' => Http::response(['code' => 'EGP'], 200),
            self::STORE . '/wp-json/wc/v3/orders' => Http::response([
                'id' => 42,
                'number' => '42',
                'status' => 'pending',
                'total' => '250.00',
                'currency' => 'EGP',
                'line_items' => [['product_id' => 18, 'quantity' => 1]],
            ], 201),
        ]);

        $result = (new WooCommerceService())->createOrderForChannel(
            $this->wooChannel(),
            $this->checkoutWithoutEmail()
        );

        $this->assertSame(42, $result['data']['id']);

        Http::assertSent(function ($request) {
            if (! str_ends_with($request->url(), '/wp-json/wc/v3/orders')) {
                return false;
            }
            $data = $request->data();
            // billing.email must NOT be present (it was the root cause of the 400)
            $this->assertArrayNotHasKey('email', $data['billing'] ?? []);
            // payment_method must NOT be present either
            $this->assertArrayNotHasKey('payment_method', $data);
            $this->assertArrayNotHasKey('payment_method_title', $data);
            // billing.phone SHOULD be present since we provided a phone number
            $this->assertSame('+201001234567', $data['billing']['phone'] ?? null);
            // product_id uses the external WooCommerce ID, not the local DB ID
            $this->assertSame(18, $data['line_items'][0]['product_id'] ?? null);

            return true;
        });
    }

    /**
     * When a valid email IS provided, it must still be included in the payload.
     */
    public function test_order_with_valid_email_includes_it_in_billing(): void
    {
        Http::fake([
            self::STORE . '/wp-json/wc/v3/data/currencies/current' => Http::response(['code' => 'USD'], 200),
            self::STORE . '/wp-json/wc/v3/orders' => Http::response([
                'id' => 50,
                'number' => '50',
                'status' => 'pending',
                'total' => '100.00',
                'currency' => 'USD',
                'line_items' => [],
            ], 201),
        ]);

        $checkout = $this->checkoutWithoutEmail();
        $checkout['email'] = 'customer@example.com';

        (new WooCommerceService())->createOrderForChannel($this->wooChannel(), $checkout);

        Http::assertSent(function ($request) {
            if (! str_ends_with($request->url(), '/wp-json/wc/v3/orders')) {
                return false;
            }
            $this->assertSame('customer@example.com', $request->data()['billing']['email'] ?? null);

            return true;
        });
    }

    /**
     * Reproduces the actual WooCommerce 400 response with billing validation
     * error and verifies the exception includes the provider error code and
     * message, and that the error is logged with sanitized details.
     */
    public function test_http_400_captures_provider_error_code_and_message(): void
    {
        Http::fake([
            self::STORE . '/wp-json/wc/v3/data/currencies/current' => Http::response(['code' => 'EGP'], 200),
            self::STORE . '/wp-json/wc/v3/orders' => Http::response([
                'code' => 'rest_invalid_param',
                'message' => 'Invalid parameter(s): billing',
                'data' => [
                    'status' => 400,
                    'params' => [
                        'billing' => 'billing is not a valid JSON value.',
                    ],
                ],
            ], 400),
        ]);

        Log::shouldReceive('error')
            ->once()
            ->withArgs(function (string $message, array $context) {
                return $message === 'WOOCOMMERCE_ORDER_API_REJECTED'
                    && $context['http_status'] === 400
                    && $context['provider_code'] === 'rest_invalid_param'
                    && str_contains($context['provider_message'] ?? '', 'billing')
                    && in_array('billing', $context['rejected_params'] ?? [], true)
                    && $context['product_id'] === 18;
            });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/HTTP 400.*rest_invalid_param.*billing/');

        (new WooCommerceService())->createOrderForChannel(
            $this->wooChannel(),
            $this->checkoutWithoutEmail()
        );
    }

    /**
     * Ensures that when the checkout provides neither email nor phone,
     * both fields are omitted from the billing object.
     */
    public function test_order_without_email_or_phone_omits_both_from_billing(): void
    {
        Http::fake([
            self::STORE . '/wp-json/wc/v3/data/currencies/current' => Http::response(['code' => 'SAR'], 200),
            self::STORE . '/wp-json/wc/v3/orders' => Http::response([
                'id' => 60,
                'number' => '60',
                'status' => 'pending',
                'total' => '50.00',
                'currency' => 'SAR',
                'line_items' => [],
            ], 201),
        ]);

        $checkout = [
            'commerce_external_id' => '18',
            'full_name' => 'Sara',
            'address' => '5 Olaya Street',
            'quantity' => 1,
            'commerce_product_metadata' => ['type' => 'simple'],
            // no phone, no email
        ];

        (new WooCommerceService())->createOrderForChannel($this->wooChannel(), $checkout);

        Http::assertSent(function ($request) {
            if (! str_ends_with($request->url(), '/wp-json/wc/v3/orders')) {
                return false;
            }
            $billing = $request->data()['billing'] ?? [];
            $this->assertArrayNotHasKey('email', $billing);
            $this->assertArrayNotHasKey('phone', $billing);
            $this->assertSame('Sara', $billing['first_name']);

            return true;
        });
    }
}
