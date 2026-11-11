<?php

namespace App\Services;

use App\Jobs\NotifyPaymentClient;
use App\Models\CentralPayment;
use App\Models\CentralPaymentEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class CentralPaymentProcessor
{
    public function verifyInvoice(CentralPayment $payment, string $providerPaymentId): CentralPayment
    {
        $data = app(MyFatoorahService::class)->getPaymentStatus($providerPaymentId, 'PaymentId');
        return $this->applyVerifiedStatus($payment, $data);
    }

    public function verifyInvoiceId(CentralPayment $payment): CentralPayment
    {
        $data = app(MyFatoorahService::class)->getPaymentStatus((string) $payment->myfatoorah_invoice_id, 'InvoiceId');
        return $this->applyVerifiedStatus($payment, $data);
    }

    public function applyVerifiedStatus(CentralPayment $payment, array $data): CentralPayment
    {
        $invoiceId = (string) ($data['InvoiceId'] ?? '');

        if ($invoiceId === '' || !hash_equals((string) $payment->myfatoorah_invoice_id, $invoiceId)) {
            throw new RuntimeException('MyFatoorah invoice reference did not match.');
        }

        $status = strtoupper((string) ($data['InvoiceStatus'] ?? ''));
        $transaction = collect($data['InvoiceTransactions'] ?? [])->first();
        $currency = strtoupper((string) (
            $data['CurrencyIso']
            ?? $data['DisplayCurrencyIso']
            ?? $data['DisplayCurrencyIsoAlpha']
            ?? (is_array($transaction) ? ($transaction['Currency'] ?? $transaction['PaidCurrency'] ?? '') : '')
        ));
        if ($currency === 'SR') $currency = 'SAR';

        if ($status !== 'PAID') {
            $updatedPayment = $payment;
            if (in_array($status, ['FAILED', 'EXPIRED', 'CANCELLED', 'CANCELED'], true)) {
                $payment->newQuery()->whereKey($payment->id)->whereNotIn('status', ['paid', 'refunded'])
                    ->update(['status' => strtolower($status), 'updated_at' => now()]);
                $updatedPayment = $payment->fresh();
                $reference = 'payment.' . strtolower($status) . ':' . $invoiceId;
                $event = CentralPaymentEvent::query()->firstOrCreate(
                    ['event_reference' => $reference],
                    [
                        'central_payment_id' => $payment->id,
                        'event_type' => $reference,
                        'payload' => ['invoice_id' => $invoiceId, 'status' => strtolower($status)],
                        'processing_status' => 'received',
                    ],
                );
                if ($event->wasRecentlyCreated) NotifyPaymentClient::dispatch($payment->id, $reference);
            }
            return $updatedPayment;
        }

        $actualAmount = $data['InvoiceValue'] ?? null;
        if (!is_numeric($actualAmount)
            || abs((float) $actualAmount - (float) $payment->amount) > 0.01
            || $currency !== strtoupper($payment->currency)) {
            Log::alert('Central payment rejected verified invoice amount/currency mismatch', [
                'payment_uuid' => $payment->uuid,
                'invoice_id' => $invoiceId,
                'expected_currency' => $payment->currency,
                'actual_currency' => $currency ?: null,
            ]);
            throw new RuntimeException('Verified invoice amount or currency did not match the order.');
        }

        $transactionId = is_array($transaction) ? (string) ($transaction['TransactionId'] ?? $transaction['PaymentId'] ?? '') : '';

        $paidPayment = DB::connection('central_payments')->transaction(function () use ($payment, $data, $invoiceId, $transactionId) {
            $locked = CentralPayment::query()->lockForUpdate()->findOrFail($payment->id);
            if ($locked->status === 'paid' || $locked->status === 'refunded') return $locked;

            $locked->update([
                'status' => 'paid',
                'myfatoorah_payment_id' => $transactionId ?: $locked->myfatoorah_payment_id,
                'paid_at' => now(),
                'gateway_metadata' => [
                    'invoice_id' => $invoiceId,
                    'verified_status' => 'Paid',
                    'verified_amount' => (string) $data['InvoiceValue'],
                    'verified_currency' => $data['CurrencyIso'] ?? $data['DisplayCurrencyIso'] ?? null,
                ],
            ]);

            $event = CentralPaymentEvent::query()->firstOrCreate(
                ['event_reference' => 'payment.paid:' . $invoiceId],
                [
                    'central_payment_id' => $locked->id,
                    'event_type' => 'payment.paid',
                    'payload' => ['invoice_id' => $invoiceId, 'payment_id' => $transactionId ?: null],
                    'processing_status' => 'received',
                ],
            );

            if ($event->wasRecentlyCreated) NotifyPaymentClient::dispatch($locked->id, $event->event_reference)->afterCommit();

            return $locked;
        });

        return $paidPayment;
    }
}
