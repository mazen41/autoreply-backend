<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Package;
use App\Models\PaymentIntent;
use App\Models\Subscription;
use App\Services\MyFatoorahService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class MyFatoorahController extends Controller
{
    public function __construct(private readonly MyFatoorahService $mf) {}

    // ─── POST /api/payment/myfatoorah/initiate ─────────────────────────────────
    /**
     * Creates a payment session and returns the hosted payment URL.
     * The frontend should redirect the user to that URL.
     */
    public function initiate(Request $request)
    {
        $request->validate([
            'package_id'    => 'required|exists:packages,id',
            'billing_cycle' => 'required|in:monthly,yearly',
        ]);

        $user    = Auth::user();
        $package = Package::findOrFail($request->package_id);

        $amount   = $request->billing_cycle === 'yearly'
            ? ($package->yearly_price ?? $package->price * 12 * 0.8)
            : $package->price;
        $currency = 'SAR';

        try {
            // Step 1 – discover payment methods (required by MyFatoorah flow)
            $initData = $this->mf->initiatePayment($amount, $currency);

            // Use PaymentMethodId = 0 to show all methods on the hosted page
            $paymentMethodId = 0;

            // Step 2 – execute to get redirect URL
            $executeData = $this->mf->executePayment([
                'PaymentMethodId'   => $paymentMethodId,
                'InvoiceValue'      => $amount,
                'CustomerName'      => $user->name,
                'CustomerEmail'     => $user->email,
                'DisplayCurrencyIso'=> $currency,
                'MobileCountryCode' => '+966',
                'CustomerMobile'    => $user->phone ?? '',
                'Language'          => 'EN',
                'CallBackUrl'       => 'https://nazbiz.io/payment/callback',
                'ErrorUrl'          => 'https://nazbiz.io/payment/error',
                'UserDefinedField'  => (string) $user->id,
            ]);

            // Persist the pending intent
            $intent = PaymentIntent::create([
                'user_id'               => $user->id,
                'package_id'            => $package->id,
                'billing_cycle'         => $request->billing_cycle,
                'status'                => 'pending',
                'payment_gateway'       => 'myfatoorah',
                'myfatoorah_invoice_id' => (string) $executeData['InvoiceId'],
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
            Log::error('[MyFatoorah] initiate error', ['error' => $e->getMessage(), 'user' => $user->id]);
            return response()->json(['message' => 'Payment initiation failed. Please try again.'], 502);
        }
    }

    // ─── GET /api/payment/callback ─────────────────────────────────────────────
    /**
     * MyFatoorah redirects the customer here after a successful payment.
     * Query string contains: paymentId (and optionally Id = InvoiceId).
     * We call GetPaymentStatus as a reliable fallback to confirm the status.
     */
    public function callback(Request $request)
    {
        $paymentId = $request->query('paymentId') ?? $request->query('Id');

        if (! $paymentId) {
            Log::warning('[MyFatoorah] Callback called without paymentId');
            return response()->json(['message' => 'Missing payment identifier.'], 400);
        }

        try {
            $data = $this->mf->getPaymentStatus($paymentId, 'PaymentId');
            return $this->handlePaymentData($data, 'callback');
        } catch (\Exception $e) {
            Log::error('[MyFatoorah] Callback GetPaymentStatus error', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Could not verify payment. Please contact support.'], 502);
        }
    }

    // ─── GET /api/payment/error ────────────────────────────────────────────────
    /**
     * MyFatoorah redirects here when the customer cancels or payment fails.
     */
    public function error(Request $request)
    {
        $paymentId = $request->query('paymentId') ?? $request->query('Id');
        Log::info('[MyFatoorah] Payment error/cancel redirect', ['paymentId' => $paymentId]);

        // Try to mark the intent as failed if we can identify it
        if ($paymentId) {
            try {
                $data = $this->mf->getPaymentStatus($paymentId, 'PaymentId');
                $invoiceId = $data['InvoiceId'] ?? null;
                if ($invoiceId) {
                    PaymentIntent::where('myfatoorah_invoice_id', $invoiceId)
                        ->where('status', 'pending')
                        ->update(['status' => 'failed']);
                }
            } catch (\Exception $e) {
                Log::warning('[MyFatoorah] Could not fetch status on error URL', ['error' => $e->getMessage()]);
            }
        }

        return response()->json(['message' => 'Payment was not completed.'], 200);
    }

    // ─── Shared: activate subscription after confirmed payment ─────────────────
    private function handlePaymentData(array $data, string $source): \Illuminate\Http\JsonResponse
    {
        $invoiceId     = (string) ($data['InvoiceId'] ?? '');
        $invoiceStatus = $data['InvoiceStatus'] ?? '';

        // Find the matching PaymentIntent
        $intent = PaymentIntent::where('myfatoorah_invoice_id', $invoiceId)
            ->where('payment_gateway', 'myfatoorah')
            ->first();

        if (! $intent) {
            Log::warning("[MyFatoorah] No PaymentIntent found for InvoiceId={$invoiceId} (source={$source})");
            return response()->json(['message' => 'Invoice not recognised.'], 404);
        }

        if ($invoiceStatus === 'Paid' && $intent->status !== 'completed') {
            DB::transaction(function () use ($intent, $data) {
                $txn = collect($data['InvoiceTransactions'] ?? [])->firstWhere('TransactionStatus', 'Succss')
                    ?? collect($data['InvoiceTransactions'] ?? [])->first();

                $intent->update([
                    'status'                  => 'completed',
                    'myfatoorah_payment_id'   => $txn['TransactionId'] ?? null,
                    'gateway_response'        => $data,
                ]);

                $package = Package::find($intent->package_id);

                Subscription::updateOrCreate(
                    ['user_id' => $intent->user_id],
                    [
                        'package_id'             => $intent->package_id,
                        'status'                 => 'active',
                        'billing_cycle'          => $intent->billing_cycle,
                        'amount_paid'            => $data['InvoiceValue'] ?? 0,
                        'payment_gateway'        => 'myfatoorah',
                        'myfatoorah_invoice_id'  => (string) ($data['InvoiceId'] ?? ''),
                        'myfatoorah_payment_id'  => $txn['TransactionId'] ?? null,
                        'starts_at'              => now(),
                        'ends_at'                => $intent->billing_cycle === 'yearly'
                            ? now()->addYear()
                            : now()->addMonth(),
                    ]
                );
            });

            return response()->json(['message' => 'Subscription activated successfully.']);
        }

        return response()->json(['message' => 'Payment status: ' . $invoiceStatus]);
    }
}
