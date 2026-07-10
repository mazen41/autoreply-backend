<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Package;
use App\Models\Subscription;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PaymentController extends Controller
{
    public function createPayment(Request $request)
    {
        $request->validate([
            'package_id' => 'required|exists:packages,id',
            'billing_cycle' => 'required|in:monthly,yearly',
            'source' => 'required|array',
        ]);

        $package = Package::findOrFail($request->package_id);
        $user = auth()->user();

        // Calculate amount based on billing cycle
        $amount = $request->billing_cycle === 'yearly' 
            ? $package->price_yearly 
            : $package->price_monthly;

        // Convert to halalas (1 SAR = 100 halalas)
        $amountInHalalas = $amount * 100;

        // Prepare payment data
        $paymentData = [
            'amount' => $amountInHalalas,
            'currency' => 'SAR',
            'description' => "Naz Autoreply - {$package->name} plan",
            'callback_url' => env('APP_URL') . '/api/payments/callback',
            'source' => $request->source,
        ];

        // Call Moyasar API
        $response = Http::withBasicAuth(
            env('MOYASAR_SECRET_KEY'), 
            ''
        )->post('https://api.moyasar.com/v1/payments', $paymentData);

        if (!$response->successful()) {
            Log::error('Moyasar payment creation failed', [
                'response' => $response->body(),
                'data' => $paymentData
            ]);
            return response()->json([
                'message' => 'Payment creation failed',
                'error' => $response->json()
            ], 500);
        }

        return response()->json($response->json());
    }

    public function callback(Request $request)
    {
        $paymentId = $request->query('id');
        
        if (!$paymentId) {
            return redirect(env('FRONTEND_URL') . '/pricing?payment=failed');
        }

        // Verify payment status with Moyasar
        $response = Http::withBasicAuth(
            env('MOYASAR_SECRET_KEY'), 
            ''
        )->get("https://api.moyasar.com/v1/payments/{$paymentId}");

        if (!$response->successful()) {
            Log::error('Moyasar payment verification failed', ['payment_id' => $paymentId]);
            return redirect(env('FRONTEND_URL') . '/pricing?payment=failed');
        }

        $payment = $response->json();

        if ($payment['status'] === 'paid') {
            // Create subscription
            $packageId = $payment['metadata']['package_id'] ?? null;
            $billingCycle = $payment['metadata']['billing_cycle'] ?? 'monthly';
            
            if (!$packageId) {
                Log::error('Package ID missing in payment metadata', ['payment' => $payment]);
                return redirect(env('FRONTEND_URL') . '/pricing?payment=failed');
            }

            $package = Package::findOrFail($packageId);
            $user = auth()->check() ? auth()->user() : null;

            if (!$user) {
                // Handle case where user needs to be authenticated
                return redirect(env('FRONTEND_URL') . '/login?payment_success=' . $paymentId);
            }

            // Calculate subscription end date
            $startDate = now();
            $endDate = $billingCycle === 'yearly' 
                ? $startDate->addYear() 
                : $startDate->addMonth();

            // Cancel any existing active subscription
            $existingSubscription = $user->activeSubscription;
            if ($existingSubscription) {
                $existingSubscription->update([
                    'status' => 'cancelled',
                    'cancelled_at' => now()
                ]);
            }

            // Create new subscription
            $subscription = Subscription::create([
                'user_id' => $user->id,
                'package_id' => $package->id,
                'status' => 'active',
                'billing_cycle' => $billingCycle,
                'amount_paid' => $payment['amount'] / 100, // Convert back to SAR
                'moyasar_payment_id' => $payment['id'],
                'moyasar_invoice_id' => $payment['id'],
                'starts_at' => $startDate,
                'ends_at' => $endDate,
            ]);

            Log::info('Subscription created successfully', [
                'subscription_id' => $subscription->id,
                'user_id' => $user->id,
                'package_id' => $package->id
            ]);

            return redirect(env('FRONTEND_URL') . '/dashboard?payment=success');
        }

        return redirect(env('FRONTEND_URL') . '/pricing?payment=failed');
    }

    public function webhook(Request $request)
    {
        $payload = $request->all();
        $signature = $request->header('X-Moyasar-Signature');

        // Verify webhook signature
        $expectedSignature = hash_hmac('sha256', json_encode($payload), env('MOYASAR_WEBHOOK_SECRET'));
        
        if (!hash_equals($expectedSignature, $signature)) {
            Log::error('Invalid webhook signature', ['payload' => $payload]);
            return response()->json(['message' => 'Invalid signature'], 401);
        }

        $event = $payload['event'] ?? null;

        if ($event === 'payment.paid') {
            $payment = $payload['data'];
            $this->handlePaymentPaid($payment);
        } elseif ($event === 'payment.failed') {
            $payment = $payload['data'];
            $this->handlePaymentFailed($payment);
        }

        return response()->json(['message' => 'Webhook processed']);
    }

    protected function handlePaymentPaid($payment)
    {
        // Handle subscription renewal
        $subscription = Subscription::where('moyasar_payment_id', $payment['id'])->first();
        
        if ($subscription) {
            $subscription->update([
                'status' => 'active',
                'starts_at' => now(),
                'ends_at' => $subscription->billing_cycle === 'yearly' 
                    ? now()->addYear() 
                    : now()->addMonth()
            ]);

            Log::info('Subscription renewed via webhook', [
                'subscription_id' => $subscription->id,
                'payment_id' => $payment['id']
            ]);
        }
    }

    protected function handlePaymentFailed($payment)
    {
        $subscription = Subscription::where('moyasar_payment_id', $payment['id'])->first();
        
        if ($subscription) {
            $subscription->update(['status' => 'expired']);
            
            Log::info('Subscription expired due to failed payment', [
                'subscription_id' => $subscription->id,
                'payment_id' => $payment['id']
            ]);
        }
    }
}
