<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Package;
use App\Models\PaymentIntent;
use App\Models\Subscription;
use App\Services\ActiveBusiness;
use App\Services\MyFatoorahService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class MyFatoorahController extends Controller
{
    public function __construct(private readonly MyFatoorahService $mf) {}

    // ─── POST /api/payment/myfatoorah/initiate ─────────────────────────────────
    public function initiate(Request $request)
    {
        $business = app(ActiveBusiness::class)->resolve($request, permission: 'manage_billing');
        $request->validate([
            'package_id'    => 'required|exists:packages,id',
            'billing_cycle' => 'required|in:monthly,yearly',
            'auto_renew'    => 'sometimes|boolean',
        ]);

        $autoRenew = $request->boolean('auto_renew');
        if ($autoRenew && $request->billing_cycle !== 'monthly') {
            return response()->json(['message' => 'Automatic renewal is currently available for monthly plans only. Annual plans can be renewed manually.'], 422);
        }
        if ($autoRenew && !config('services.myfatoorah.recurring_enabled')) {
            return response()->json(['message' => 'Automatic renewal is not enabled for this payment account yet. Please choose manual renewal or contact support.'], 422);
        }
        if (config('services.central_payments.enabled') && $autoRenew) {
            return response()->json(['message' => 'Automatic renewal is not yet available through centralized checkout. Choose manual renewal.'], 422);
        }

        $user    = Auth::user();
        $package = Package::findOrFail($request->package_id);
        abort_unless($package->is_active, 422, 'This plan is not available for purchase.');

        $amount = $request->billing_cycle === 'yearly'
            ? (float) ($package->price_yearly ?? $package->price_monthly * 12 * 0.8)
            : (float) $package->price_monthly;
        if ($amount <= 0) {
            return response()->json(['message' => 'Free plans must be activated without checkout.'], 422);
        }

        $activeSubscription = app(\App\Services\UsageMeter::class)->subscriptionFor($business);
        if ($activeSubscription?->auto_renew_enabled) {
            return response()->json(['message' => 'Turn off automatic renewal on your current plan before starting another subscription checkout.'], 409);
        }

        $currency = 'SAR';

        $intent = PaymentIntent::create([
            'user_id'               => $user->id,
            'business_id'           => $business->id,
            'package_id'            => $package->id,
            'billing_cycle'         => $request->billing_cycle,
            'status'                => 'pending',
            'payment_gateway'       => config('services.central_payments.enabled') ? 'central' : 'myfatoorah',
            'expected_amount_minor' => (int) round($amount * 100),
            'expected_currency'    => $currency,
            'auto_renew_requested' => $autoRenew,
            'auto_renew_consented_at' => $autoRenew ? now() : null,
        ]);

        if (config('services.central_payments.enabled')) {
            try {
                $centralPayment = app(\App\Services\CentralPaymentClientService::class)
                    ->createHostedPayment((string) $intent->id, 'nazbiz-subscription-' . $intent->id);
                $intent->update(['gateway_response' => ['central_payment_id' => $centralPayment['central_payment_id']]]);
                return response()->json([
                    'payment_url' => $centralPayment['payment_url'],
                    'payment_id' => $centralPayment['central_payment_id'],
                    'intent_id' => $intent->id,
                ]);
            } catch (\Throwable $exception) {
                $intent->update(['status' => 'failed', 'failure_reason' => 'central_checkout_unavailable']);
                Log::error('[Central Payment] NazBiz checkout request failed', [
                    'payment_intent_id' => $intent->id,
                    'exception_type' => get_class($exception),
                ]);
                return response()->json(['message' => 'Payment initiation failed. Please try again.'], 502);
            }
        }

        try {
            // Step 1 – discover payment methods enabled on this merchant account.
            $paymentMethods = $this->mf->initiatePayment($amount, $currency);
            $availableMethods = collect($paymentMethods['PaymentMethods'] ?? [])
                ->filter(fn ($method) => isset($method['PaymentMethodId']) && (int) $method['PaymentMethodId'] > 0);

            if ($availableMethods->isEmpty()) {
                throw new \RuntimeException('MyFatoorah returned no enabled payment methods for this account.');
            }

            // ExecutePayment requires a real enabled method ID for this account;
            // PaymentMethodId=0 is rejected by the live Saudi API.
            $paymentMethodId = (int) $availableMethods->first()['PaymentMethodId'];

            // Step 2 – create a hosted payment request using an enabled method.
            $executeData = $this->mf->executePayment([
                'PaymentMethodId'    => $paymentMethodId,
                'InvoiceValue'       => $amount,
                'CustomerName'       => $user->name,
                'CustomerEmail'      => $user->email,
                'DisplayCurrencyIso' => $currency,
                'MobileCountryCode'  => '+966',
                'CustomerMobile'     => $user->phone ?? '',
                'Language'           => 'EN',
                'CallBackUrl'        => 'https://nazbiz.io/payment/callback',
                'ErrorUrl'           => 'https://nazbiz.io/payment/error',
                'UserDefinedField'   => (string) $intent->id,
                ...($autoRenew ? ['RecurringModel' => [
                    'RecurringType' => 'Monthly',
                    'Iteration' => 0,
                    'RetryCount' => 3,
                ]] : []),
            ]);

            $paymentUrl = $executeData['PaymentURL'] ?? $executeData['InvoiceURL'] ?? null;
            if (!is_string($paymentUrl) || !filter_var($paymentUrl, FILTER_VALIDATE_URL)) {
                throw new \RuntimeException('MyFatoorah did not return a valid payment URL.');
            }

            $intent->update([
                'myfatoorah_invoice_id' => (string) $executeData['InvoiceId'],
                'myfatoorah_recurring_id' => $executeData['RecurringId'] ?? null,
                'gateway_response'      => $executeData,
            ]);

            return response()->json([
                'invoice_id'  => $executeData['InvoiceId'],
                'payment_url' => $paymentUrl,
                'intent_id'   => $intent->id,
            ]);

        } catch (\Exception $e) {
            $intent->update(['status' => 'failed']);
            Log::error('[MyFatoorah] initiate error', ['exception_type' => get_class($e), 'user_id' => $user->id]);
            return response()->json(['message' => 'Payment initiation failed. Please try again.'], 502);
        }
    }

    // ─── GET /api/payment/callback ─────────────────────────────────────────────
    public function callback(Request $request)
    {
        $paymentId = $request->query('paymentId') ?? $request->query('Id');

        if (! $paymentId) {
            Log::warning('[MyFatoorah] Callback called without paymentId');
            return response()->json(['message' => 'Missing payment identifier.'], 400);
        }

        try {
            $data = $this->mf->getPaymentStatus($paymentId, 'PaymentId');
            return $this->handlePaymentData($data);
        } catch (\Exception $e) {
            Log::error('[MyFatoorah] Callback error', ['exception_type' => get_class($e)]);
            return response()->json(['message' => 'Could not verify payment. Please contact support.'], 502);
        }
    }

    public function centralStatus(Request $request, string $centralPaymentId)
    {
        $business = app(ActiveBusiness::class)->resolve($request, permission: 'manage_billing');
        $intent = PaymentIntent::query()
            ->where('payment_gateway', 'central')
            ->where('user_id', Auth::id())
            ->where('business_id', $business->id)
            ->where('gateway_response->central_payment_id', $centralPaymentId)
            ->first();

        if (!$intent) return response()->json(['message' => 'Payment not found.'], 404);
        if ($intent->status === 'completed') return response()->json(['status' => 'completed']);

        try {
            $remote = app(\App\Services\CentralPaymentClientService::class)->getPayment($centralPaymentId);
            if (in_array($remote['status'] ?? '', ['failed', 'expired', 'cancelled', 'canceled'], true)) {
                $intent->update(['status' => 'failed', 'failure_reason' => 'central_' . $remote['status']]);
                return response()->json(['status' => 'failed']);
            }
            return response()->json(['status' => ($remote['status'] ?? '') === 'paid' ? 'processing' : 'pending']);
        } catch (\Throwable $exception) {
            Log::warning('[Central Payment] Could not retrieve checkout status', [
                'payment_intent_id' => $intent->id,
                'exception_type' => get_class($exception),
            ]);
            return response()->json(['status' => 'pending'], 202);
        }
    }

    // ─── GET /api/payment/error ────────────────────────────────────────────────
    public function error(Request $request)
    {
        $paymentId = $request->query('paymentId') ?? $request->query('Id');
        Log::info('[MyFatoorah] Payment error/cancel redirect', ['paymentId' => $paymentId]);

        if ($paymentId) {
            try {
                $data      = $this->mf->getPaymentStatus($paymentId, 'PaymentId');
                $invoiceId = (string) ($data['InvoiceId'] ?? '');
                if ($invoiceId) {
                    PaymentIntent::where('myfatoorah_invoice_id', $invoiceId)
                        ->where('status', 'pending')
                        ->update(['status' => 'failed']);
                    \App\Models\AiCreditPurchase::query()->where('invoice_id', $invoiceId)
                        ->where('status', 'pending')->update(['status' => 'failed']);
                }
            } catch (\Exception $e) {
                Log::warning('[MyFatoorah] Could not fetch status on error URL', ['exception_type' => get_class($e)]);
            }
        }

        return response()->json(['message' => 'Payment was not completed.'], 200);
    }

    // ─── Shared: activate subscription after confirmed payment ─────────────────
    protected function handlePaymentData(array $data): \Illuminate\Http\JsonResponse
    {
        $invoiceId     = (string) ($data['InvoiceId'] ?? '');
        $invoiceStatus = $data['InvoiceStatus'] ?? '';

        $intent = PaymentIntent::where('myfatoorah_invoice_id', $invoiceId)
            ->where('payment_gateway', 'myfatoorah')
            ->first();

        if (! $intent) {
            if (app(\App\Http\Controllers\Api\AiCreditsController::class)->fulfillVerifiedPurchase($data)) {
                return response()->json(['message' => 'Credit purchase was received and verified.']);
            }
            Log::warning("[MyFatoorah] No PaymentIntent found for InvoiceId={$invoiceId}");
            return response()->json(['message' => 'Invoice not recognised.'], 404);
        }

        if ($invoiceStatus === 'Paid' && $intent->status === 'pending') {
            $transaction = collect($data['InvoiceTransactions'] ?? [])->first();
            $actualCurrency = $data['CurrencyIso']
                ?? $data['DisplayCurrencyIso']
                ?? $data['DisplayCurrencyIsoAlpha']
                ?? (is_array($transaction) ? ($transaction['Currency'] ?? $transaction['PaidCurrency'] ?? null) : null);
            $actualMinor = isset($data['InvoiceValue']) && is_numeric($data['InvoiceValue'])
                ? (int) round((float) $data['InvoiceValue'] * 100)
                : null;
            if (!app(\App\Services\PaymentIntentAmountVerifier::class)->verify(
                $intent, $actualMinor, $actualCurrency !== null ? (string) $actualCurrency : null, 'myfatoorah'
            )) {
                return response()->json(['message' => 'Payment amount or currency did not match the invoice.'], 422);
            }

            DB::transaction(function () use ($intent, $data) {
                $lockedIntent = PaymentIntent::query()->lockForUpdate()->find($intent->id);
                if (!$lockedIntent || $lockedIntent->status === 'completed') {
                    return;
                }

                $businessId = $lockedIntent->business_id ?: $lockedIntent->user?->business_id;
                if (!$businessId || !\App\Models\BusinessProfile::whereKey($businessId)->exists()) {
                    Log::error('[MyFatoorah] PaymentIntent has no valid business scope', [
                        'payment_intent_id' => $lockedIntent->id,
                        'user_id' => $lockedIntent->user_id,
                        'business_id' => $lockedIntent->business_id,
                    ]);
                    return;
                }

                $txn = collect($data['InvoiceTransactions'] ?? [])->first();

                $lockedIntent->update([
                    'status'                => 'completed',
                    'myfatoorah_payment_id' => $txn['TransactionId'] ?? null,
                    'gateway_response'      => $data,
                ]);

                Subscription::query()
                    ->where('business_id', $businessId)
                    ->whereIn('status', ['active', 'trial'])
                    ->update(['status' => 'cancelled', 'cancelled_at' => now()]);

                Subscription::create([
                        'business_id'           => $businessId,
                        'user_id'               => $lockedIntent->user_id,
                        'package_id'            => $lockedIntent->package_id,
                        'status'                => 'active',
                        'billing_cycle'         => $lockedIntent->billing_cycle,
                        'amount_paid'           => $data['InvoiceValue'] ?? 0,
                        'payment_gateway'       => 'myfatoorah',
                        'myfatoorah_invoice_id' => (string) ($data['InvoiceId'] ?? ''),
                        'myfatoorah_payment_id' => $txn['TransactionId'] ?? null,
                        'myfatoorah_recurring_id' => $lockedIntent->myfatoorah_recurring_id,
                        'auto_renew_enabled' => $lockedIntent->auto_renew_requested && !empty($lockedIntent->myfatoorah_recurring_id),
                        'auto_renew_consented_at' => $lockedIntent->auto_renew_consented_at,
                        'starts_at'             => now(),
                        'ends_at'               => $lockedIntent->billing_cycle === 'yearly'
                            ? now()->addYear()
                            : now()->addMonth(),
                    ]);
            });

            return response()->json(['message' => 'Subscription activated successfully.']);
        }

        return response()->json(['message' => 'Payment status: ' . $invoiceStatus]);
    }
}
