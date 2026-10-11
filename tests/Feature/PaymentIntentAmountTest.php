<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\MyFatoorahController;
use App\Models\BusinessProfile;
use App\Models\Package;
use App\Models\PaymentIntent;
use App\Models\User;
use App\Services\PaymobService;
use App\Services\MyFatoorahService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentIntentAmountTest extends TestCase
{
    use RefreshDatabase;

    public function test_matching_paymob_amount_and_currency_activate_subscription(): void
    {
        [$controller, $intent] = $this->makeIntentController();

        $result = $controller->fulfill([
            'id' => 9001,
            'success' => true,
            'amount_cents' => 12500,
            'currency' => 'EGP',
            'order' => ['id' => 4001, 'merchant_order_id' => (string) $intent->id],
        ]);

        $this->assertTrue($result);
        $this->assertDatabaseHas('subscriptions', ['payment_gateway' => 'paymob', 'amount_paid' => 125]);
    }

    public function test_mismatched_paymob_amount_or_currency_fails_intent_without_activating_plan(): void
    {
        foreach ([
            ['amount_cents' => 12499, 'currency' => 'EGP', 'reason' => 'amount_mismatch'],
            ['amount_cents' => 12500, 'currency' => 'SAR', 'reason' => 'currency_mismatch'],
        ] as $case) {
            [$controller, $intent] = $this->makeIntentController();

            $result = $controller->fulfill([
                'id' => random_int(10000, 99999),
                'success' => true,
                'amount_cents' => $case['amount_cents'],
                'currency' => $case['currency'],
                'order' => ['id' => random_int(1000, 9999), 'merchant_order_id' => (string) $intent->id],
            ]);

            $this->assertFalse($result);
            $this->assertSame('failed', $intent->fresh()->status);
            $this->assertSame($case['reason'], $intent->fresh()->failure_reason);
            $this->assertDatabaseMissing('subscriptions', ['business_id' => $intent->business_id, 'status' => 'active']);
        }
    }

    public function test_myfatoorah_rejects_amount_or_currency_mismatch_and_accepts_exact_invoice(): void
    {
        config(['services.myfatoorah.api_key' => 'test-key', 'services.myfatoorah.base_url' => 'https://example.test']);

        foreach ([
            ['amount' => 125, 'currency' => 'SAR', 'status' => 200],
            ['amount' => 124.99, 'currency' => 'SAR', 'status' => 422],
            ['amount' => 125, 'currency' => 'EGP', 'status' => 422],
        ] as $index => $case) {
            [$intent, $businessId] = $this->makeMyFatoorahIntent();
            $controller = new class(app(MyFatoorahService::class)) extends MyFatoorahController {
                public function fulfill(array $data): \Illuminate\Http\JsonResponse
                {
                    return $this->handlePaymentData($data);
                }
            };
            $response = $controller->fulfill([
                'InvoiceId' => $intent->myfatoorah_invoice_id,
                'InvoiceStatus' => 'Paid',
                'InvoiceValue' => $case['amount'],
                'InvoiceTransactions' => [['TransactionId' => 'mf-' . $index, 'Currency' => $case['currency']]],
            ]);

            $this->assertSame($case['status'], $response->getStatusCode());
            if ($case['status'] === 200) {
                $this->assertDatabaseHas('subscriptions', ['business_id' => $businessId, 'payment_gateway' => 'myfatoorah']);
            } else {
                $this->assertSame('failed', $intent->fresh()->status);
                $this->assertDatabaseMissing('subscriptions', ['business_id' => $businessId, 'payment_gateway' => 'myfatoorah']);
            }
        }
    }

    private function makeIntentController(): array
    {
        $user = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $user->id]);
        $package = Package::create([
            'name' => 'Test plan', 'name_ar' => 'خطة اختبار', 'price_monthly' => 125, 'price_yearly' => 1200,
            'is_active' => true, 'features' => [],
        ]);
        $intent = PaymentIntent::create([
            'user_id' => $user->id, 'business_id' => $business->id,
            'package_id' => $package->id, 'billing_cycle' => 'monthly', 'status' => 'pending',
            'expected_amount_minor' => 12500, 'expected_currency' => 'EGP', 'payment_gateway' => 'paymob',
        ]);
        $controller = new class(app(PaymobService::class)) extends PaymentController {
            public function fulfill(array $transaction): bool
            {
                return $this->fulfillFromTransaction($transaction);
            }
        };

        return [$controller, $intent];
    }

    private function makeMyFatoorahIntent(): array
    {
        $user = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $user->id]);
        $package = Package::create([
            'name' => 'Test plan', 'name_ar' => 'خطة اختبار', 'price_monthly' => 125, 'price_yearly' => 1200,
            'is_active' => true, 'features' => [],
        ]);
        $intent = PaymentIntent::create([
            'user_id' => $user->id, 'business_id' => $business->id,
            'package_id' => $package->id, 'billing_cycle' => 'monthly', 'status' => 'pending',
            'expected_amount_minor' => 12500, 'expected_currency' => 'SAR',
            'payment_gateway' => 'myfatoorah', 'myfatoorah_invoice_id' => 'invoice-' . random_int(10000, 99999),
        ]);

        return [$intent, $business->id];
    }
}
