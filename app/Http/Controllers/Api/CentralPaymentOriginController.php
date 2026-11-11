<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PaymentClient;
use App\Models\PaymentIntent;
use App\Models\Subscription;
use App\Services\PaymentIntentAmountVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CentralPaymentOriginController extends Controller
{
    public function verifyOrder(Request $request): JsonResponse
    {
        /** @var PaymentClient $client */
        $client = $request->attributes->get('central_payment_client');
        $input = $request->validate(['external_order_id' => ['required', 'string', 'max:160']]);

        // NazBiz's first adapter uses a pending PaymentIntent as its local
        // order. Other product backends provide this same contract with their
        // own order/catalog lookup and price calculation.
        if ($client->slug !== 'nazbiz') {
            return response()->json(['verified' => false, 'message' => 'This website order adapter is not installed.'], 501);
        }

        $intent = PaymentIntent::query()->with(['package', 'user'])->find($input['external_order_id']);
        if (!$intent || $intent->status !== 'pending' || $intent->payment_gateway !== 'central') {
            return response()->json(['verified' => false, 'message' => 'Order is not eligible for payment.'], 404);
        }

        $expected = $intent->expected_amount_minor;
        if ($expected === null || $expected <= 0 || !$intent->package?->is_active) {
            return response()->json(['verified' => false, 'message' => 'Order pricing is unavailable.'], 422);
        }

        return response()->json([
            'verified' => true,
            'order' => [
                'external_order_id' => (string) $intent->id,
                'external_customer_id' => (string) $intent->user_id,
                'external_package_id' => (string) $intent->package_id,
                'package_name' => $intent->package->name,
                'customer_email' => $intent->user?->email,
                'customer_name' => $intent->user?->name,
                'amount' => number_format($expected / 100, 2, '.', ''),
                'currency' => strtoupper((string) $intent->expected_currency),
                'customer' => ['phone' => $intent->user?->phone],
            ],
        ]);
    }

    public function confirmPayment(Request $request): JsonResponse
    {
        /** @var PaymentClient $client */
        $client = $request->attributes->get('central_payment_client');
        $input = $request->validate([
            'event_id' => ['required', 'string', 'max:190'],
            'central_payment_id' => ['required', 'uuid'],
            'external_order_id' => ['required', 'string', 'max:160'],
            'external_package_id' => ['nullable', 'string', 'max:160'],
            'external_customer_id' => ['nullable', 'string', 'max:160'],
            'payment_status' => ['required', 'in:paid,failed,expired,cancelled,canceled,refunded'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'currency' => ['required', 'string', 'size:3'],
            'myfatoorah_invoice_id' => ['required', 'string', 'max:80'],
            'myfatoorah_payment_id' => ['nullable', 'string', 'max:80'],
            'paid_at' => ['nullable', 'date'],
        ]);

        if ($client->slug !== 'nazbiz') {
            return response()->json(['acknowledged' => false, 'message' => 'This website confirmation adapter is not installed.'], 501);
        }

        $intent = PaymentIntent::query()->whereKey($input['external_order_id'])->first();
        if (!$intent || $intent->payment_gateway !== 'central'
            || (string) $intent->package_id !== (string) $input['external_package_id']
            || (string) $intent->user_id !== (string) $input['external_customer_id']) {
            return response()->json(['acknowledged' => false, 'message' => 'Local order association did not match.'], 404);
        }

        if ($input['payment_status'] === 'paid') {
            $actualMinor = (int) round((float) $input['amount'] * 100);
            if (!app(PaymentIntentAmountVerifier::class)->verify(
                $intent, $actualMinor, strtoupper($input['currency']), 'myfatoorah'
            )) {
                return response()->json(['acknowledged' => false, 'message' => 'Order amount or currency did not match.'], 409);
            }

            DB::transaction(function () use ($intent, $input): void {
                $locked = PaymentIntent::query()->lockForUpdate()->find($intent->id);
                if (!$locked || $locked->status === 'completed') return;
                $businessId = $locked->business_id ?: $locked->user?->business_id;
                abort_unless($businessId && \App\Models\BusinessProfile::whereKey($businessId)->exists(), 409, 'Order workspace is unavailable.');

                $locked->update([
                    'status' => 'completed',
                    'myfatoorah_invoice_id' => $input['myfatoorah_invoice_id'],
                    'myfatoorah_payment_id' => $input['myfatoorah_payment_id'],
                    'gateway_response' => ['central_payment_id' => $input['central_payment_id']],
                ]);
                Subscription::query()->where('business_id', $businessId)->whereIn('status', ['active', 'trial'])
                    ->update(['status' => 'cancelled', 'cancelled_at' => now()]);
                Subscription::query()->create([
                    'business_id' => $businessId,
                    'user_id' => $locked->user_id,
                    'package_id' => $locked->package_id,
                    'status' => 'active',
                    'billing_cycle' => $locked->billing_cycle,
                    'amount_paid' => $input['amount'],
                    'payment_gateway' => 'myfatoorah',
                    'myfatoorah_invoice_id' => $input['myfatoorah_invoice_id'],
                    'myfatoorah_payment_id' => $input['myfatoorah_payment_id'],
                    'starts_at' => now(),
                    'ends_at' => $locked->billing_cycle === 'yearly' ? now()->addYear() : now()->addMonth(),
                ]);
            });
        } elseif (in_array($input['payment_status'], ['failed', 'expired', 'cancelled', 'canceled'], true)) {
            PaymentIntent::query()->whereKey($intent->id)->where('status', 'pending')->update([
                'status' => 'failed', 'failure_reason' => 'central_' . $input['payment_status'],
            ]);
        }

        Log::info('Central payment acknowledged by originating NazBiz application', [
            'client_id' => $client->public_id,
            'payment_intent_id' => $intent->id,
            'central_payment_id' => $input['central_payment_id'],
            'status' => $input['payment_status'],
        ]);

        return response()->json(['acknowledged' => true]);
    }
}
