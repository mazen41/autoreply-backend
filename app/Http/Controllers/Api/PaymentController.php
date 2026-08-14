<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Package;
use App\Models\Subscription;
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
            'first_name'     => $user->name ?? 'Customer',
            'last_name'      => '',
            'street'         => 'N/A',
            'building'       => 'N/A',
            'phone_number'   => $user->phone ?? '+20000000000',
            'shipping_method' => 'NA',
            'postal_code'    => 'NA',
            'city'           => 'NA',
            'country'        => 'EGY',
            'state'          => 'NA',
        ];

        $metadata = [
            'package_id'    => (string) $package->id,
            'package_name'  => $package->name,
            'billing_cycle' => $request->billing_cycle,
            'user_id'       => (string) $user->id,
            'description'   => "nazbiz - {$package->name} plan",
        ];

        try {
            $intention = $this->paymob->createIntention(
                $amountCents,
                $billingData,
                $metadata,
                $redirectUrl
            );

            return response()->json([
                'checkout_url'  => $intention['checkout_url'],
                'client_secret' => $intention['client_secret'],
                'order_id'      => $intention['order_id'],
            ]);
        } catch (\Exception $e) {
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
        Log::info('Paymob: Callback hit', [
            'query' => $request->query(),
        ]);

        $success       = $request->query('success');
        $transactionId = $request->query('id');
        $orderId       = $request->query('order');
        $hmac          = $request->query('hmac');

        // Basic validation
        if (!$transactionId) {
            Log::warning('Paymob callback: missing transaction id');
            return redirect(config('services.frontend_url') . '/pricing?payment=failed');
        }

        // Verify HMAC from query string (Paymob sends it as a query param on redirect)
        if ($hmac) {
            // For redirect callbacks, Paymob signs query params differently.
            // We verify by fetching the transaction directly instead.
        }

        if ($success !== 'true') {
            Log::warning('Paymob callback: payment not successful', [
                'success' => $success,
                'transaction_id' => $transactionId,
            ]);
            return redirect(config('services.frontend_url') . '/pricing?payment=failed');
        }

        // Fetch the transaction from Paymob to confirm it is genuinely paid
        $transaction = $this->paymob->getTransaction($transactionId);

        if (!$transaction || !($transaction['success'] ?? false)) {
            Log::error('Paymob callback: transaction verification failed', [
                'transaction_id' => $transactionId,
                'transaction'    => $transaction,
            ]);
            return redirect(config('services.frontend_url') . '/pricing?payment=failed');
        }

        // Extract metadata
        $metadata     = $transaction['order']['merchant_order_id'] ?? null;
        $orderObj     = $transaction['order'] ?? [];
        $paymobOrder  = $orderObj['id'] ?? null;

        // Metadata is stored in the order's shipping_data or extras
        // Paymob stores our metadata in order.merchant_order_id or order.items/shipping_data
        // Fallback: get from order's data
        $packageId    = null;
        $billingCycle = 'monthly';
        $userId       = null;

        // Try to retrieve from order items or merchant_order_id
        if (isset($transaction['order']['items'])) {
            foreach ($transaction['order']['items'] as $item) {
                // metadata was passed in items description — not ideal, try other paths
            }
        }

        // Best approach: use the transaction's payment token metadata
        // Paymob v1 intention stores metadata in order.metadata
        $orderMetadata = $orderObj['metadata'] ?? $transaction['metadata'] ?? [];
        $packageId     = $orderMetadata['package_id']    ?? null;
        $billingCycle  = $orderMetadata['billing_cycle'] ?? 'monthly';
        $userId        = $orderMetadata['user_id']       ?? null;

        if (!$packageId || !$userId) {
            Log::error('Paymob callback: missing metadata', [
                'transaction_id' => $transactionId,
                'order_metadata' => $orderMetadata,
            ]);
            return redirect(config('services.frontend_url') . '/pricing?payment=failed');
        }

        $package = Package::find($packageId);
        $user    = \App\Models\User::find($userId);

        if (!$package || !$user) {
            Log::error('Paymob callback: package or user not found', [
                'package_id' => $packageId,
                'user_id'    => $userId,
            ]);
            return redirect(config('services.frontend_url') . '/pricing?payment=failed');
        }

        // Avoid duplicate subscription creation
        $existing = Subscription::where('paymob_transaction_id', (string) $transactionId)->first();
        if ($existing) {
            Log::info('Paymob callback: duplicate callback, subscription already exists', [
                'subscription_id' => $existing->id,
            ]);
            return redirect(config('services.frontend_url') . '/dashboard?payment=success');
        }

        // Cancel any existing active subscription
        $activeSubscription = $user->activeSubscription;
        if ($activeSubscription) {
            $activeSubscription->update([
                'status'       => 'cancelled',
                'cancelled_at' => now(),
            ]);
        }

        // Create new subscription
        $startDate = now();
        $endDate   = $billingCycle === 'yearly'
            ? $startDate->copy()->addYear()
            : $startDate->copy()->addMonth();

        $amountPaid = ($transaction['amount_cents'] ?? 0) / 100;

        $subscription = Subscription::create([
            'user_id'              => $user->id,
            'package_id'           => $package->id,
            'status'               => 'active',
            'billing_cycle'        => $billingCycle,
            'amount_paid'          => $amountPaid,
            'paymob_order_id'      => (string) $paymobOrder,
            'paymob_transaction_id'=> (string) $transactionId,
            'starts_at'            => $startDate,
            'ends_at'              => $endDate,
        ]);

        Log::info('Paymob: Subscription created', [
            'subscription_id' => $subscription->id,
            'user_id'         => $user->id,
            'package_id'      => $package->id,
        ]);

        return redirect(config('services.frontend_url') . '/dashboard?payment=success');
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Paymob webhook notification (public — no auth, no CSRF)
    // POST /api/payments/webhook
    // ──────────────────────────────────────────────────────────────────────────
    public function webhook(Request $request)
    {
        $payload   = $request->all();
        $type      = $request->query('type');
        $hmac      = $request->query('hmac');

        Log::info('Paymob: Webhook received', ['type' => $type]);

        // Only process transaction notifications
        if ($type !== 'TRANSACTION') {
            return response()->json(['message' => 'Ignored non-transaction webhook']);
        }

        // Verify HMAC
        if (!$this->paymob->verifyHmac($payload, (string) $hmac)) {
            Log::error('Paymob: Invalid webhook HMAC', ['received_hmac' => $hmac]);
            return response()->json(['message' => 'Invalid signature'], 401);
        }

        $obj     = $payload['obj'] ?? [];
        $success = $obj['success'] ?? false;
        $pending = $obj['pending'] ?? false;

        $transactionId = $obj['id']       ?? null;
        $paymobOrderId = $obj['order']['id'] ?? null;

        if ($success && !$pending) {
            $this->handlePaymentSuccess($obj);
        } elseif (!$success && !$pending) {
            $this->handlePaymentFailed($obj);
        }

        return response()->json(['message' => 'Webhook processed']);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Internal helpers
    // ──────────────────────────────────────────────────────────────────────────

    protected function handlePaymentSuccess(array $obj): void
    {
        $transactionId = (string) ($obj['id'] ?? '');
        $paymobOrderId = (string) ($obj['order']['id'] ?? '');

        // Find subscription by paymob_order_id for renewals
        $subscription = Subscription::where('paymob_order_id', $paymobOrderId)->first();

        if ($subscription) {
            $subscription->update([
                'status'               => 'active',
                'paymob_transaction_id'=> $transactionId,
                'starts_at'            => now(),
                'ends_at'              => $subscription->billing_cycle === 'yearly'
                    ? now()->addYear()
                    : now()->addMonth(),
            ]);

            Log::info('Paymob webhook: subscription renewed', [
                'subscription_id' => $subscription->id,
                'transaction_id'  => $transactionId,
            ]);
        }
        // New subscriptions are handled via the callback redirect
    }

    protected function handlePaymentFailed(array $obj): void
    {
        $paymobOrderId = (string) ($obj['order']['id'] ?? '');

        $subscription = Subscription::where('paymob_order_id', $paymobOrderId)->first();

        if ($subscription && $subscription->status !== 'active') {
            $subscription->update(['status' => 'expired']);

            Log::info('Paymob webhook: subscription expired due to failed payment', [
                'subscription_id' => $subscription->id,
            ]);
        }
    }
}
