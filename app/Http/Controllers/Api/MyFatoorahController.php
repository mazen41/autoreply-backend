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
            'payment_gateway'       => 'myfatoorah',
            'expected_amount_minor' => (int) round($amount * 100),
            'expected_currency'    => $currency,
            'auto_renew_requested' => $autoRenew,
            'auto_renew_consented_at' => $autoRenew ? now() : null,
        ]);

        try {
            // Step 1 – discover payment methods
            $this->mf->initiatePayment($amount, $currency);

            // Step 2 – execute to get redirect URL (PaymentMethodId=0 shows all methods)
            $executeData = $this->mf->executePayment([
                'PaymentMethodId'    => 0,
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

            $intent->update([
                'myfatoorah_invoice_id' => (string) $executeData['InvoiceId'],
                'myfatoorah_recurring_id' => $executeData['RecurringId'] ?? null,
                'gateway_response'      => $executeData,
            ]);

            return response()->json([
                'invoice_id'  => $executeData['InvoiceId'],
                'payment_url' => $executeData['IsDirectPayment']
                    ? $executeData['PaymentURL']
                    : $executeData['InvoiceURL'],
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
