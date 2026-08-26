<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PaymentIntent;
use App\Models\Subscription;
use App\Services\MyFatoorahService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MyFatoorahWebhookController extends Controller
{
    public function __construct(private readonly MyFatoorahService $mf) {}

    /**
     * POST /api/webhooks/myfatoorah
     * Receives MyFatoorah webhook events (Webhook V2).
     */
    public function handle(Request $request)
    {
        // Read raw body before any Laravel parsing so HMAC is correct
        $rawBody   = $request->getContent();
        $signature = $request->header('MyFatoorah-Signature', '');

        if (! $this->mf->verifyWebhookSignature($rawBody, $signature)) {
            Log::warning('[MyFatoorah Webhook] Invalid signature', [
                'ip'        => $request->ip(),
                'signature' => $signature,
            ]);
            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        $payload = $request->json()->all();
        $event   = $payload['Event'] ?? $payload['event'] ?? null;

        Log::info('[MyFatoorah Webhook] Received', ['event' => $event]);

        if (in_array($event, ['PAYMENT_STATUS_CHANGED', 'payment_status_changed'])) {
            return $this->handlePaymentStatusChanged($payload);
        }

        // Unknown event — always return 200 to acknowledge
        return response()->json(['message' => 'Event ignored.'], 200);
    }

    private function handlePaymentStatusChanged(array $payload)
    {
        $invoiceId = (string) ($payload['Data']['InvoiceId'] ?? $payload['InvoiceId'] ?? '');
        $status    = $payload['Data']['InvoiceStatus'] ?? $payload['InvoiceStatus'] ?? '';

        if (! $invoiceId) {
            Log::error('[MyFatoorah Webhook] Missing InvoiceId in payload', $payload);
            return response()->json(['message' => 'Missing InvoiceId.'], 200);
        }

        Log::info('[MyFatoorah Webhook] PAYMENT_STATUS_CHANGED', [
            'invoice_id' => $invoiceId,
            'status'     => $status,
        ]);

        // Re-verify via API for security — prevents replayed/spoofed payloads
        $apiData = [];
        try {
            $apiData = $this->mf->getPaymentStatus($invoiceId, 'InvoiceId');
            $status  = $apiData['InvoiceStatus'] ?? $status;
        } catch (\Exception $e) {
            Log::error('[MyFatoorah Webhook] GetPaymentStatus failed', ['error' => $e->getMessage()]);
            // Fall back to webhook payload status
        }

        $intent = PaymentIntent::where('myfatoorah_invoice_id', $invoiceId)
            ->where('payment_gateway', 'myfatoorah')
            ->first();

        if (! $intent) {
            Log::warning("[MyFatoorah Webhook] No PaymentIntent found for InvoiceId={$invoiceId}");
            return response()->json(['message' => 'Invoice not tracked.'], 200);
        }

        if ($status === 'Paid' && $intent->status !== 'completed') {
            DB::transaction(function () use ($intent, $apiData, $invoiceId) {
                $txn = collect($apiData['InvoiceTransactions'] ?? [])->first();

                $intent->update([
                    'status'                => 'completed',
                    'myfatoorah_payment_id' => $txn['TransactionId'] ?? null,
                    'gateway_response'      => $apiData,
                ]);

                Subscription::updateOrCreate(
                    ['user_id' => $intent->user_id],
                    [
                        'package_id'            => $intent->package_id,
                        'status'                => 'active',
                        'billing_cycle'         => $intent->billing_cycle,
                        'amount_paid'           => $apiData['InvoiceValue'] ?? 0,
                        'payment_gateway'       => 'myfatoorah',
                        'myfatoorah_invoice_id' => $invoiceId,
                        'myfatoorah_payment_id' => $txn['TransactionId'] ?? null,
                        'starts_at'             => now(),
                        'ends_at'               => $intent->billing_cycle === 'yearly'
                            ? now()->addYear()
                            : now()->addMonth(),
                    ]
                );
            });

            Log::info("[MyFatoorah Webhook] Subscription activated for user {$intent->user_id}");

        } elseif (in_array($status, ['Failed', 'Expired', 'Cancelled'])) {
            $intent->update(['status' => 'failed']);
            Log::info("[MyFatoorah Webhook] Payment marked failed. Status={$status}");
        }

        // Always 200 to acknowledge receipt
        return response()->json(['message' => 'OK'], 200);
    }
}
