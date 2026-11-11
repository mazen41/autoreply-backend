<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CentralPayment;
use App\Models\PaymentClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class CentralPaymentsController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $input = $request->validate([
            'external_order_id' => ['required', 'string', 'max:160'],
            'idempotency_key' => ['required', 'string', 'max:160', 'regex:/^[A-Za-z0-9._:-]+$/'],
        ]);

        /** @var PaymentClient $client */
        $client = $request->attributes->get('central_payment_client');
        $existing = CentralPayment::query()
            ->where('payment_client_id', $client->id)
            ->where('idempotency_key', $input['idempotency_key'])
            ->first();

        if ($existing) {
            if ($existing->checkout_url && in_array($existing->status, ['pending', 'paid'], true)) {
                return response()->json($this->paymentResponse($existing), 200);
            }
            return response()->json([
                'message' => 'This payment request is already being processed. Check its status before retrying.',
                'payment_id' => $existing->uuid,
                'status' => $existing->status,
            ], 409);
        }

        try {
            $verifiedOrder = app(\App\Services\CentralPaymentOrderVerifier::class)
                ->verify($client, $input['external_order_id']);
        } catch (Throwable $exception) {
            Log::warning('Central payment order verification failed', [
                'client_id' => $client->public_id,
                'external_order_id' => $input['external_order_id'],
                'exception_type' => get_class($exception),
            ]);
            return response()->json(['message' => 'The originating website could not verify this order.'], 502);
        }

        try {
            $payment = DB::connection('central_payments')->transaction(function () use ($client, $input, $verifiedOrder) {
                return CentralPayment::query()->create([
                    'uuid' => (string) Str::uuid(),
                    'payment_client_id' => $client->id,
                    'external_order_id' => $input['external_order_id'],
                    'external_customer_id' => $verifiedOrder['external_customer_id'] ?? null,
                    'external_package_id' => $verifiedOrder['external_package_id'] ?? null,
                    'package_name' => $verifiedOrder['package_name'] ?? null,
                    'customer_email' => $verifiedOrder['customer_email'] ?? null,
                    'customer_name' => $verifiedOrder['customer_name'] ?? null,
                    'amount' => $verifiedOrder['amount'],
                    'currency' => $verifiedOrder['currency'],
                    'status' => 'creating',
                    'idempotency_key' => $input['idempotency_key'],
                    'customer_details' => $verifiedOrder['customer'] ?? null,
                    'verified_order' => $verifiedOrder,
                ]);
            });
        } catch (\Illuminate\Database\QueryException $exception) {
            $existing = CentralPayment::query()
                ->where('payment_client_id', $client->id)
                ->where('idempotency_key', $input['idempotency_key'])
                ->first();
            if ($existing) {
                return response()->json([
                    'message' => 'This payment request is already being processed.',
                    'payment_id' => $existing->uuid,
                    'status' => $existing->status,
                ], 409);
            }
            throw $exception;
        }

        try {
            $gateway = app(\App\Services\MyFatoorahService::class);
            $methods = $gateway->initiatePayment((float) $payment->amount, $payment->currency);
            $method = collect($methods['PaymentMethods'] ?? [])
                ->first(fn ($item) => isset($item['PaymentMethodId']) && (int) $item['PaymentMethodId'] > 0);
            if (!$method) throw new \RuntimeException('No enabled MyFatoorah payment method.');

            $customer = $payment->customer_details ?? [];
            $result = $gateway->executePayment([
                'PaymentMethodId' => (int) $method['PaymentMethodId'],
                'InvoiceValue' => (float) $payment->amount,
                'DisplayCurrencyIso' => $payment->currency,
                'CustomerName' => $payment->customer_name ?: 'Customer',
                'CustomerEmail' => $payment->customer_email ?: '',
                'CustomerMobile' => $customer['phone'] ?? '',
                'MobileCountryCode' => $customer['mobile_country_code'] ?? '',
                'CallBackUrl' => url('/api/v1/payments/callback'),
                'ErrorUrl' => url('/api/v1/payments/callback'),
                'UserDefinedField' => $payment->uuid,
            ]);

            $checkoutUrl = $result['PaymentURL'] ?? $result['InvoiceURL'] ?? null;
            $invoiceId = $result['InvoiceId'] ?? null;
            if (!is_string($checkoutUrl) || !filter_var($checkoutUrl, FILTER_VALIDATE_URL) || !$invoiceId) {
                throw new \RuntimeException('MyFatoorah returned an incomplete hosted checkout response.');
            }

            $payment->update([
                'status' => 'pending',
                'myfatoorah_invoice_id' => (string) $invoiceId,
                'myfatoorah_payment_id' => isset($result['InvoiceTransactions'][0]['PaymentId'])
                    ? (string) $result['InvoiceTransactions'][0]['PaymentId'] : null,
                'checkout_url' => $checkoutUrl,
                'expires_at' => now()->addHours(24),
                'gateway_metadata' => ['recurring_supported' => false],
            ]);

            return response()->json($this->paymentResponse($payment->fresh()), 201);
        } catch (Throwable $exception) {
            // Keep the row in `creating`: a network timeout can happen after
            // MyFatoorah accepted the invoice, so a blind retry could charge twice.
            Log::error('Central MyFatoorah invoice creation needs reconciliation', [
                'payment_uuid' => $payment->uuid,
                'client_id' => $client->public_id,
                'exception_type' => get_class($exception),
            ]);
            return response()->json([
                'message' => 'Payment creation is being reconciled. Check this payment status before retrying.',
                'payment_id' => $payment->uuid,
                'status' => 'creating',
            ], 502);
        }
    }

    public function show(Request $request, string $paymentUuid): JsonResponse
    {
        /** @var PaymentClient $client */
        $client = $request->attributes->get('central_payment_client');

        $payment = CentralPayment::query()
            ->where('uuid', $paymentUuid)
            ->where('payment_client_id', $client->id)
            ->first();

        if (!$payment) {
            return response()->json(['message' => 'Payment not found.'], 404);
        }

        return response()->json([
            'payment_id' => $payment->uuid,
            'external_order_id' => $payment->external_order_id,
            'status' => $payment->status,
            'amount' => $payment->amount,
            'currency' => $payment->currency,
            'checkout_url' => $payment->checkout_url,
            'paid_at' => $payment->paid_at?->toISOString(),
        ]);
    }

    public function callback(Request $request)
    {
        $providerPaymentId = (string) ($request->query('paymentId') ?? $request->query('Id') ?? '');
        if ($providerPaymentId === '') {
            return response()->json(['message' => 'Missing payment identifier.'], 400);
        }

        try {
            $data = app(\App\Services\MyFatoorahService::class)->getPaymentStatus($providerPaymentId, 'PaymentId');
            $invoiceId = (string) ($data['InvoiceId'] ?? '');
            $payment = CentralPayment::query()->where('myfatoorah_invoice_id', $invoiceId)->first();
            if (!$payment) return response()->json(['message' => 'Payment reference not found.'], 404);

            $payment = app(\App\Services\CentralPaymentProcessor::class)->applyVerifiedStatus($payment, $data);

            $resultPath = $payment->verified_order['payment_result_path']
                ?? $payment->client->metadata['payment_result_path']
                ?? '/payment/result';
            if (is_string($resultPath)) {
                $resultPath = str_replace(
                    ['{external_order_id}', '{central_payment_id}'],
                    [rawurlencode((string) $payment->external_order_id), rawurlencode((string) $payment->uuid)],
                    $resultPath,
                );
            }
            if (!is_string($resultPath) || !str_starts_with($resultPath, '/') || str_starts_with($resultPath, '//')) {
                $resultPath = '/payment/result';
            }
            $separator = str_contains($resultPath, '?') ? '&' : '?';
            return redirect()->away(rtrim($payment->client->frontend_url, '/') . $resultPath . $separator . http_build_query([
                'central_payment_id' => $payment->uuid,
            ]));
        } catch (Throwable $exception) {
            Log::error('Central payment browser callback could not be verified', [
                'exception_type' => get_class($exception),
            ]);
            return response()->json(['message' => 'Payment verification is pending. Return to the website shortly.'], 502);
        }
    }

    private function paymentResponse(CentralPayment $payment): array
    {
        return [
            'payment_id' => $payment->uuid,
            'external_order_id' => $payment->external_order_id,
            'status' => $payment->status,
            'amount' => $payment->amount,
            'currency' => $payment->currency,
            'checkout_url' => $payment->checkout_url,
            'expires_at' => $payment->expires_at?->toISOString(),
        ];
    }
}
