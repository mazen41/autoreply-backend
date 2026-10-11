<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\MyFatoorahController;
use App\Models\BusinessProfile;
use App\Models\Package;
use App\Models\PaymentIntent;
use App\Models\Subscription;
use App\Models\User;
use App\Services\MyFatoorahService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SubscriptionAutoRenewTest extends TestCase
{
    use RefreshDatabase;

    public function test_monthly_auto_renew_requires_explicit_opt_in_and_stores_provider_recurring_id(): void
    {
        config([
            'services.myfatoorah.api_key' => 'test-key',
            'services.myfatoorah.base_url' => 'https://mf.example.test',
            'services.myfatoorah.recurring_enabled' => true,
        ]);
        Http::fake([
            'mf.example.test/v2/InitiatePayment' => Http::response(['IsSuccess' => true, 'Data' => ['PaymentMethods' => []]]),
            'mf.example.test/v2/ExecutePayment' => Http::response(['IsSuccess' => true, 'Data' => [
                'InvoiceId' => 501, 'RecurringId' => 'RECUR-501', 'IsDirectPayment' => false,
                'InvoiceURL' => 'https://checkout.example.test/501',
            ]]),
        ]);
        [$user, $business, $plan] = $this->setupBilling();

        $this->actingAs($user)->withHeader('X-Business-Id', (string) $business->id)
            ->postJson('/api/payment/myfatoorah/initiate', [
                'package_id' => $plan->id, 'billing_cycle' => 'monthly', 'auto_renew' => true,
            ])
            ->assertOk()
            ->assertJsonPath('payment_url', 'https://checkout.example.test/501');

        $intent = PaymentIntent::firstOrFail();
        $this->assertTrue($intent->auto_renew_requested);
        $this->assertNotNull($intent->auto_renew_consented_at);
        $this->assertSame('RECUR-501', $intent->myfatoorah_recurring_id);
        $controller = new class(app(MyFatoorahService::class)) extends MyFatoorahController {
            public function fulfill(array $data): \Illuminate\Http\JsonResponse
            {
                return $this->handlePaymentData($data);
            }
        };
        $controller->fulfill([
            'InvoiceId' => '501', 'InvoiceStatus' => 'Paid', 'InvoiceValue' => 50,
            'InvoiceTransactions' => [['TransactionId' => 'TX-501', 'Currency' => 'SAR']],
        ]);
        $this->assertDatabaseHas('subscriptions', [
            'business_id' => $business->id, 'myfatoorah_recurring_id' => 'RECUR-501',
            'auto_renew_enabled' => true,
        ]);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/v2/ExecutePayment')
            && ($request['RecurringModel']['RecurringType'] ?? null) === 'Monthly'
            && ($request['RecurringModel']['Iteration'] ?? null) === 0
            && ($request['RecurringModel']['RetryCount'] ?? null) === 3);
    }

    public function test_auto_renew_is_rejected_until_provider_feature_is_enabled_and_for_yearly_plans(): void
    {
        config(['services.myfatoorah.recurring_enabled' => false]);
        [$user, $business, $plan] = $this->setupBilling();
        $this->actingAs($user)->withHeader('X-Business-Id', (string) $business->id)
            ->postJson('/api/payment/myfatoorah/initiate', [
                'package_id' => $plan->id, 'billing_cycle' => 'monthly', 'auto_renew' => true,
            ])
            ->assertUnprocessable();
        $this->assertSame(0, PaymentIntent::count());
        Http::assertNothingSent();

        config(['services.myfatoorah.recurring_enabled' => true]);
        $this->postJson('/api/payment/myfatoorah/initiate', [
            'package_id' => $plan->id, 'billing_cycle' => 'yearly', 'auto_renew' => true,
        ])->assertUnprocessable();
        $this->assertSame(0, PaymentIntent::count());
        Http::assertNothingSent();
    }

    public function test_turning_auto_renew_off_cancels_provider_recurring_and_keeps_plan_active(): void
    {
        config(['services.myfatoorah.api_key' => 'test-key', 'services.myfatoorah.base_url' => 'https://mf.example.test']);
        Http::fake(['mf.example.test/v2/CancelRecurringPayment*' => Http::response(['IsSuccess' => true, 'Data' => true])]);
        [$user, $business, $plan] = $this->setupBilling();
        $subscription = Subscription::create([
            'user_id' => $user->id, 'business_id' => $business->id, 'package_id' => $plan->id,
            'status' => 'active', 'billing_cycle' => 'monthly', 'amount_paid' => 50,
            'payment_gateway' => 'myfatoorah', 'myfatoorah_recurring_id' => 'RECUR-501',
            'auto_renew_enabled' => true, 'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth(),
        ]);

        $this->actingAs($user)->withHeader('X-Business-Id', (string) $business->id)
            ->postJson('/api/subscriptions/auto-renew/disable')
            ->assertOk();

        $this->assertFalse($subscription->fresh()->auto_renew_enabled);
        $this->assertSame('active', $subscription->fresh()->status);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'CancelRecurringPayment?recurringId=RECUR-501'));
    }

    public function test_recurring_webhook_renews_once_even_if_provider_retries_event(): void
    {
        config([
            'services.myfatoorah.webhook_secret' => 'webhook-test-secret',
            'services.myfatoorah.api_key' => 'test-key',
        ]);
        [$user, $business, $plan] = $this->setupBilling();
        $subscription = Subscription::create([
            'user_id' => $user->id, 'business_id' => $business->id, 'package_id' => $plan->id,
            'status' => 'active', 'billing_cycle' => 'monthly', 'amount_paid' => 50,
            'payment_gateway' => 'myfatoorah', 'myfatoorah_recurring_id' => 'RECUR-501',
            'auto_renew_enabled' => true, 'starts_at' => now()->subMonth(), 'ends_at' => now()->subSecond(),
        ]);
        $payload = [
            'Event' => ['Name' => 'RECURRING_UPDATES'],
            'Data' => [
                'Recurring' => ['Id' => 'RECUR-501', 'Status' => 'ACTIVE', 'InitialInvoiceId' => '500'],
                'Payment' => [
                    'Invoice' => ['Id' => '501', 'Status' => 'PAID'],
                    'Transaction' => ['Id' => 'TX-501', 'Status' => 'SUCCESS'],
                ],
                'Amount' => ['ValueInDisplayCurrency' => '50.00', 'DisplayCurrency' => 'SAR'],
            ],
        ];
        $signed = 'Recurring.Id=RECUR-501,Recurring.Status=ACTIVE,Recurring.InitialInvoiceId=500';
        $headers = ['MyFatoorah-Signature' => hash_hmac('sha256', $signed, 'webhook-test-secret')];
        $originalEnd = $subscription->ends_at->copy();

        $this->postJson('/api/webhooks/myfatoorah', $payload, $headers)->assertOk();
        $renewedEnd = $subscription->fresh()->ends_at;
        $this->assertTrue($renewedEnd->greaterThan($originalEnd));
        $this->postJson('/api/webhooks/myfatoorah', $payload, $headers)->assertOk();
        $this->assertTrue($subscription->fresh()->ends_at->equalTo($renewedEnd));
        $this->assertDatabaseCount('subscription_renewal_invoices', 1);
    }

    private function setupBilling(): array
    {
        $user = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $user->id]);
        $user->update(['business_id' => $business->id]);
        $plan = Package::create([
            'name' => 'Paid plan', 'name_ar' => 'باقة', 'price_monthly' => 50, 'price_yearly' => 500,
            'ai_replies_limit' => 500, 'channels_limit' => 5, 'outbound_messages_limit' => 1000,
            'is_active' => true,
        ]);

        return [$user, $business, $plan];
    }
}
