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
        $payload = $request->json()->all();
        $event = $payload['Event'] ?? $payload['event'] ?? null;
        $eventName = is_array($event) ? ($event['Name'] ?? null) : $event;

        if (in_array(strtoupper((string) $eventName), ['RECURRING_UPDATES', 'RECURRING_UPDATE'])) {
            return $this->handleRecurringUpdate($payload, $signature);
        }

        if (! $this->mf->verifyWebhookSignature($rawBody, $signature)) {
            app(\App\Support\OperationalMetricCounter::class)->increment('webhook_signature_failures');
            Log::warning('[MyFatoorah Webhook] Invalid signature');
            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        Log::info('[MyFatoorah Webhook] Received', ['event' => $event]);

        if (in_array($eventName, ['PAYMENT_STATUS_CHANGED', 'payment_status_changed'])) {
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
            Log::error('[MyFatoorah Webhook] Missing InvoiceId in payload');
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
            Log::error('[MyFatoorah Webhook] GetPaymentStatus failed', ['exception_type' => get_class($e)]);
            // Fall back to webhook payload status
        }

        $intent = PaymentIntent::where('myfatoorah_invoice_id', $invoiceId)
            ->where('payment_gateway', 'myfatoorah')
            ->first();

        if (! $intent) {
            Log::warning("[MyFatoorah Webhook] No PaymentIntent found for InvoiceId={$invoiceId}");
            return response()->json(['message' => 'Invoice not tracked.'], 200);
        }

        if ($status === 'Paid' && $intent->status === 'pending') {
            $transaction = collect($apiData['InvoiceTransactions'] ?? [])->first();
            $actualCurrency = $apiData['CurrencyIso']
                ?? $apiData['DisplayCurrencyIso']
                ?? $apiData['DisplayCurrencyIsoAlpha']
                ?? (is_array($transaction) ? ($transaction['Currency'] ?? $transaction['PaidCurrency'] ?? null) : null);
            $actualMinor = isset($apiData['InvoiceValue']) && is_numeric($apiData['InvoiceValue'])
                ? (int) round((float) $apiData['InvoiceValue'] * 100)
                : null;
            if (!app(\App\Services\PaymentIntentAmountVerifier::class)->verify(
                $intent, $actualMinor, $actualCurrency !== null ? (string) $actualCurrency : null, 'myfatoorah'
            )) {
                return response()->json(['message' => 'Payment amount or currency did not match the invoice.'], 200);
            }

            DB::transaction(function () use ($intent, $apiData, $invoiceId) {
                $lockedIntent = PaymentIntent::query()->lockForUpdate()->find($intent->id);
                if (!$lockedIntent || $lockedIntent->status === 'completed') {
                    return;
                }

                $businessId = $lockedIntent->business_id ?: $lockedIntent->user?->business_id;
                if (!$businessId || !\App\Models\BusinessProfile::whereKey($businessId)->exists()) {
                    Log::error('[MyFatoorah Webhook] PaymentIntent has no valid business scope', [
                        'payment_intent_id' => $lockedIntent->id,
                        'user_id' => $lockedIntent->user_id,
                        'business_id' => $lockedIntent->business_id,
                    ]);
                    return;
                }

                $txn = collect($apiData['InvoiceTransactions'] ?? [])->first();

                $lockedIntent->update([
                    'status'                => 'completed',
                    'myfatoorah_payment_id' => $txn['TransactionId'] ?? null,
                    'gateway_response'      => $apiData,
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
                        'amount_paid'           => $apiData['InvoiceValue'] ?? 0,
                        'payment_gateway'       => 'myfatoorah',
                        'myfatoorah_invoice_id' => $invoiceId,
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

            Log::info("[MyFatoorah Webhook] Subscription activated for user {$intent->user_id}");

        } elseif (in_array($status, ['Failed', 'Expired', 'Cancelled'])) {
            $intent->update(['status' => 'failed']);
            Log::info("[MyFatoorah Webhook] Payment marked failed. Status={$status}");
        }

        // Always 200 to acknowledge receipt
        return response()->json(['message' => 'OK'], 200);
    }

    private function handleRecurringUpdate(array $payload, string $signature)
    {
        if (!$this->mf->verifyRecurringWebhookSignature($payload, $signature)) {
            app(\App\Support\OperationalMetricCounter::class)->increment('webhook_signature_failures');
            Log::warning('[MyFatoorah Webhook] Invalid recurring event signature');
            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        $data = $payload['Data'] ?? [];
        $recurring = $data['Recurring'] ?? [];
        $invoice = $data['Payment']['Invoice'] ?? [];
        $transaction = $data['Payment']['Transaction'] ?? [];
        $invoiceId = (string) ($invoice['Id'] ?? '');
        $recurringId = (string) ($recurring['Id'] ?? '');

        $subscription = Subscription::query()
            ->where('payment_gateway', 'myfatoorah')
            ->where('myfatoorah_recurring_id', $recurringId)
            ->first();

        if (!$subscription) {
            Log::info('[MyFatoorah Webhook] Recurring update has no matching subscription');
            return response()->json(['message' => 'Recurring subscription not tracked.'], 200);
        }

        if (strtoupper((string) ($invoice['Status'] ?? '')) === 'PAID'
            && strtoupper((string) ($transaction['Status'] ?? '')) === 'SUCCESS'
            && $invoiceId !== '') {
            $amountData = $data['Amount'] ?? [];
            $amount = $amountData['ValueInDisplayCurrency'] ?? $amountData['ValueInPayCurrency'] ?? null;
            $currency = strtoupper((string) ($amountData['DisplayCurrency'] ?? $amountData['PayCurrency'] ?? ''));
            if (!is_numeric($amount) || abs((float) $amount - (float) $subscription->amount_paid) > 0.01 || $currency !== 'SAR') {
                Log::warning('[MyFatoorah Webhook] Recurring renewal amount/currency did not match subscription', [
                    'subscription_id' => $subscription->id,
                    'invoice_id' => $invoiceId,
                ]);
                return response()->json(['message' => 'Renewal payment did not match the subscription.'], 200);
            }

            DB::transaction(function () use ($subscription, $invoiceId, $transaction, $amount): void {
                $locked = Subscription::query()->lockForUpdate()->find($subscription->id);
                if (!$locked || !$locked->auto_renew_enabled || DB::table('subscription_renewal_invoices')
                    ->where('gateway', 'myfatoorah')->where('external_invoice_id', $invoiceId)->exists()) {
                    return;
                }

                DB::table('subscription_renewal_invoices')->insert([
                    'subscription_id' => $locked->id,
                    'gateway' => 'myfatoorah',
                    'external_invoice_id' => $invoiceId,
                    'external_transaction_id' => isset($transaction['Id']) ? (string) $transaction['Id'] : null,
                    'amount' => $amount,
                    'paid_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $termEnd = $locked->ends_at && $locked->ends_at->isFuture() ? $locked->ends_at->copy() : now();
                $locked->update([
                    'ends_at' => $termEnd->addMonth(),
                    'amount_paid' => $amount,
                    'grace_period_ends_at' => null,
                ]);
            });
        } elseif (strtoupper((string) ($recurring['Status'] ?? '')) === 'UNCOMPLETED') {
            $graceEnd = now()->addDays(3);
            $subscription->update([
                'auto_renew_enabled' => false,
                'grace_period_ends_at' => $graceEnd,
                'ends_at' => $graceEnd,
            ]);
            Log::warning('[MyFatoorah Webhook] Automatic renewal exhausted provider retries', [
                'subscription_id' => $subscription->id,
            ]);
        } elseif (in_array(strtoupper((string) ($recurring['Status'] ?? '')), ['CANCELED', 'COMPLETED'], true)) {
            $subscription->update(['auto_renew_enabled' => false]);
        }

        return response()->json(['message' => 'Recurring event processed.'], 200);
    }
}
