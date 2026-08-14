<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Package;
use App\Models\PaymentIntent;
use App\Models\Subscription;
use App\Models\User;
use App\Services\PaymobService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PaymentController extends Controller
{
    protected PaymobService $paymob;

    public function __construct(PaymobService $paymob)
    {
        $this->paymob = $paymob;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Create a Paymob payment intention (auth required)
    // POST /api/payments/create
    // ──────────────────────────────────────────────────────────────────────────
    public function createPayment(Request $request)
    {
        $request->validate([
            'package_id'    => 'required|exists:packages,id',
            'billing_cycle' => 'required|in:monthly,yearly',
        ]);

        $package = Package::findOrFail($request->package_id);
        $user    = auth()->user();

        // Paymob rejects blank billing_data fields (e.g. "last_name": [""] fails
        // validation), so split the single `name` field and fall back safely.
        $nameParts = preg_split('/\s+/', trim($user->name ?? 'Customer'), 2);
        $firstName = $nameParts[0] !== '' ? $nameParts[0] : 'Customer';
        $lastName  = $nameParts[1] ?? 'N/A';

        // Amount in smallest unit (piastres: 1 EGP = 100 piastres)
        $amount      = $request->billing_cycle === 'yearly'
            ? $package->price_yearly
            : $package->price_monthly;
        $amountCents = (int) round($amount * 100);

        // Redirect back to our callback after payment
        $redirectUrl = config('app.url') . '/api/payments/callback';

        $billingData = [
            'apartment'      => 'N/A',
            'email'          => $user->email,
            'floor'          => 'N/A',
            'first_name'     => $firstName,
            'last_name'      => $lastName,
            'street'         => 'N/A',
            'building'       => 'N/A',
            'phone_number'   => $user->phone ?? '+20000000000',
            'shipping_method' => 'NA',
            'postal_code'    => 'NA',
            'city'           => 'NA',
            'country'        => 'EGY',
            'state'          => 'NA',
        ];

        // Local record created BEFORE talking to Paymob at all. We identify
        // the order back at callback/webhook time by this row's ID (sent as
        // Paymob's `special_reference`, reliably echoed back as
        // `merchant_order_id`) instead of trying to parse Paymob's
        // metadata/extras echo, which is undocumented and was unreliable.
        $intent = PaymentIntent::create([
            'user_id'       => $user->id,
            'package_id'    => $package->id,
            'billing_cycle' => $request->billing_cycle,
            'status'        => 'pending',
        ]);

        $displayInfo = [
            'package_name' => $package->name,
            'description'  => "nazbiz - {$package->name} plan",
        ];

        try {
            $intention = $this->paymob->createIntention(
                $amountCents,
                $billingData,
                $displayInfo,
                $redirectUrl,
                (string) $intent->id
            );

            $intent->update(['paymob_order_id' => (string) $intention['order_id']]);

            return response()->json([
                'checkout_url'  => $intention['checkout_url'],
                'client_secret' => $intention['client_secret'],
                'order_id'      => $intention['order_id'],
            ]);
        } catch (\Exception $e) {
            $intent->update(['status' => 'failed']);

            Log::error('PaymentController: createPayment failed', [
                'user_id' => $user->id,
                'error'   => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Payment initiation failed',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Paymob redirect callback (public — no auth)
    // GET /api/payments/callback
    // ──────────────────────────────────────────────────────────────────────────
    public function callback(Request $request)
    {
        $query = $request->query();
        Log::info('Paymob: Callback hit', ['query' => $query]);

        $transactionId = $query['id']   ?? null;
        $hmac          = $query['hmac'] ?? null;
        $success       = $query['success'] ?? null;

        // Verify the redirect is genuinely from Paymob and untampered.
        // (This endpoint is public — previously it trusted raw query params,
        // meaning anyone could hit it manually with success=true.)
        if (!$transactionId || !$hmac || !$this->paymob->verifyRedirectHmac($query, $hmac)) {
            Log::error('Paymob callback: HMAC verification failed', ['transaction_id' => $transactionId]);
            return redirect(config('services.frontend_url') . '/pricing?payment=failed');
        }

        if ($success !== 'true') {
            Log::warning('Paymob callback: payment not successful', ['transaction_id' => $transactionId]);
            return redirect(config('services.frontend_url') . '/pricing?payment=failed');
        }

        // The redirect query string is now proven genuine, but it does NOT
        // carry merchant_order_id (our PaymentIntent link) — only the
        // HMAC-verified webhook does. Give the webhook a short window to
        // land (it's server-to-server and usually arrives first/around the
        // same time) and check for the subscription it creates.
        $subscription = null;
        for ($i = 0; $i < 8; $i++) {
            $subscription = Subscription::where('paymob_transaction_id', $transactionId)->first();
            if ($subscription) {
                break;
            }
            usleep(500_000); // 0.5s
        }

        if ($subscription) {
            return redirect(config('services.frontend_url') . '/dashboard?payment=success');
        }

        Log::warning('Paymob callback: payment verified but webhook has not fulfilled yet', [
            'transaction_id' => $transactionId,
        ]);
        // Payment is genuinely successful (HMAC-verified) — send the user to
        // the dashboard rather than "failed"; the webhook will finish
        // shortly. Adjust the frontend to show a "confirming payment" state
        // for ?payment=processing if it doesn't already.
        return redirect(config('services.frontend_url') . '/dashboard?payment=processing');
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Paymob webhook notification (public — no auth, no CSRF)
    // POST /api/payments/webhook
    // ──────────────────────────────────────────────────────────────────────────
    public function webhook(Request $request)
    {
        $payload = $request->all();
        // Paymob sends `type` as part of the JSON body, not the query
        // string — reading it via $request->query('type') was always null,
        // which made every webhook get silently ignored. Check body first,
        // fall back to query in case a differently-configured integration
        // sends it there instead.
        $type = $payload['type'] ?? $request->query('type');
        $hmac = $request->query('hmac');

        Log::info('Paymob: Webhook received', ['type' => $type, 'payload' => $payload]);

        if ($type !== 'TRANSACTION') {
            return response()->json(['message' => 'Ignored non-transaction webhook']);
        }

        if (!$this->paymob->verifyHmac($payload, (string) $hmac)) {
            Log::error('Paymob: Invalid webhook HMAC', ['received_hmac' => $hmac]);
            return response()->json(['message' => 'Invalid signature'], 401);
        }

        $obj = $payload['obj'] ?? [];

        if (($obj['success'] ?? false) && !($obj['pending'] ?? false)) {
            $this->fulfillFromTransaction($obj);
        } elseif (!($obj['success'] ?? true) && !($obj['pending'] ?? false)) {
            $this->handlePaymentFailed($obj);
        }

        return response()->json(['message' => 'Webhook processed']);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Internal helpers
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Shared fulfillment path used by BOTH the redirect callback and the
     * webhook, so whichever one fires first (or fires at all) grants the
     * package exactly once. Looks the order up via our own PaymentIntent
     * row (matched on Paymob's `merchant_order_id`, which mirrors the
     * `special_reference` we sent when creating the intention) rather than
     * trusting Paymob's metadata/extras echo.
     *
     * @return bool true if a subscription now exists for this transaction
     */
    protected function fulfillFromTransaction(array $transaction): bool
    {
        $transactionId  = (string) ($transaction['id'] ?? '');
        $orderObj       = $transaction['order'] ?? [];
        $paymobOrderId  = (string) ($orderObj['id'] ?? '');
        $merchantOrderId = $orderObj['merchant_order_id'] ?? $transaction['merchant_order_id'] ?? null;

        if (!$transactionId || !$merchantOrderId) {
            Log::error('Paymob: cannot fulfill — missing transaction id or merchant_order_id', [
                'transaction_id'    => $transactionId,
                'merchant_order_id' => $merchantOrderId,
            ]);
            return false;
        }

        // Idempotency: already fulfilled by the other path (callback vs webhook)?
        if (Subscription::where('paymob_transaction_id', $transactionId)->exists()) {
            Log::info('Paymob: transaction already fulfilled, skipping', ['transaction_id' => $transactionId]);
            return true;
        }

        $intent = PaymentIntent::find($merchantOrderId);

        if (!$intent) {
            Log::error('Paymob: no matching PaymentIntent for merchant_order_id', [
                'merchant_order_id' => $merchantOrderId,
                'transaction_id'    => $transactionId,
            ]);
            return false;
        }

        $user    = User::find($intent->user_id);
        $package = Package::find($intent->package_id);

        if (!$user || !$package) {
            Log::error('Paymob: package or user not found for PaymentIntent', [
                'payment_intent_id' => $intent->id,
                'user_id'           => $intent->user_id,
                'package_id'        => $intent->package_id,
            ]);
            return false;
        }

        $activeSubscription = $user->activeSubscription;
        if ($activeSubscription) {
            $activeSubscription->update([
                'status'       => 'cancelled',
                'cancelled_at' => now(),
            ]);
        }

        $startDate = now();
        $endDate   = $intent->billing_cycle === 'yearly'
            ? $startDate->copy()->addYear()
            : $startDate->copy()->addMonth();

        $subscription = Subscription::create([
            'user_id'               => $user->id,
            'package_id'            => $package->id,
            'status'                => 'active',
            'billing_cycle'         => $intent->billing_cycle,
            'amount_paid'           => ($transaction['amount_cents'] ?? 0) / 100,
            'paymob_order_id'       => (string) $paymobOrderId,
            'paymob_transaction_id' => $transactionId,
            'starts_at'             => $startDate,
            'ends_at'               => $endDate,
        ]);

        $intent->update([
            'status'                 => 'completed',
            'paymob_order_id'        => (string) $paymobOrderId,
            'paymob_transaction_id'  => $transactionId,
        ]);

        Log::info('Paymob: Subscription created', [
            'subscription_id' => $subscription->id,
            'user_id'          => $user->id,
            'package_id'       => $package->id,
        ]);

        return true;
    }

    protected function handlePaymentFailed(array $obj): void
    {
        $merchantOrderId = $obj['order']['merchant_order_id'] ?? $obj['merchant_order_id'] ?? null;

        if ($merchantOrderId) {
            PaymentIntent::where('id', $merchantOrderId)->update(['status' => 'failed']);
        }

        Log::info('Paymob webhook: payment failed', ['merchant_order_id' => $merchantOrderId]);
    }
}
