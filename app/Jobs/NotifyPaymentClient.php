<?php

namespace App\Jobs;

use App\Models\CentralPayment;
use App\Models\CentralPaymentEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class NotifyPaymentClient implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 8;
    public int $timeout = 20;

    public function __construct(public readonly int $paymentId, public readonly string $eventReference)
    {
        // Keep this on the application's default queue so existing production
        // workers pick it up until a dedicated payments worker is configured.
    }

    public function backoff(): array
    {
        return [10, 30, 120, 300, 900, 1800, 3600];
    }

    public function handle(): void
    {
        $payment = CentralPayment::query()->with('client')->find($this->paymentId);
        $event = CentralPaymentEvent::query()
            ->where('central_payment_id', $this->paymentId)
            ->where('event_reference', $this->eventReference)
            ->first();

        if (!$payment || !$event || $event->processing_status === 'processed') return;
        if (!$payment->client || !$payment->client->is_active) {
            $event->update(['processing_status' => 'failed', 'failure_reason' => 'Payment client is inactive.']);
            return;
        }

        $client = $payment->client;
        $path = '/api/internal/payments/confirm';
        $payload = [
            'event_id' => $this->eventReference,
            'central_payment_id' => $payment->uuid,
            'external_order_id' => $payment->external_order_id,
            'external_package_id' => $payment->external_package_id,
            'external_customer_id' => $payment->external_customer_id,
            'payment_status' => $payment->status,
            'amount' => (string) $payment->amount,
            'currency' => $payment->currency,
            'myfatoorah_invoice_id' => $payment->myfatoorah_invoice_id,
            'myfatoorah_payment_id' => $payment->myfatoorah_payment_id ?: $payment->myfatoorah_invoice_id,
            'paid_at' => $payment->paid_at?->toISOString(),
        ];
        $rawBody = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $timestamp = (string) now()->timestamp;
        $nonce = bin2hex(random_bytes(20));
        $canonical = implode("\n", ['POST', $path, $timestamp, $nonce, hash('sha256', $rawBody)]);
        $signature = hash_hmac('sha256', $canonical, $client->notification_secret);

        try {
            $response = Http::timeout(12)
                ->withHeaders([
                    'X-Central-Payment-Client' => $client->public_id,
                    'X-Central-Payment-Timestamp' => $timestamp,
                    'X-Central-Payment-Nonce' => $nonce,
                    'X-Central-Payment-Signature' => $signature,
                    'X-Central-Payment-Notification' => '1',
                ])
                ->withBody($rawBody, 'application/json')
                ->post(rtrim($client->backend_url, '/') . $path);

            if (!$response->successful() || $response->json('acknowledged') !== true) {
                throw new \RuntimeException('Origin website did not acknowledge the payment event (HTTP ' . $response->status() . ').');
            }

            $event->update(['processing_status' => 'processed', 'processed_at' => now(), 'failure_reason' => null]);
        } catch (Throwable $exception) {
            $event->update([
                'processing_status' => 'retrying',
                'failure_reason' => mb_substr($exception->getMessage(), 0, 2000),
            ]);
            Log::warning('Central payment notification attempt failed', [
                'payment_uuid' => $payment->uuid,
                'client_id' => $client->public_id,
                'event_reference' => $this->eventReference,
                'attempt' => $this->attempts(),
            ]);
            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        CentralPaymentEvent::query()
            ->where('central_payment_id', $this->paymentId)
            ->where('event_reference', $this->eventReference)
            ->where('processing_status', '!=', 'processed')
            ->update([
                'processing_status' => 'failed',
                'failure_reason' => $exception ? mb_substr($exception->getMessage(), 0, 2000) : 'Notification retries exhausted.',
                'updated_at' => now(),
            ]);
    }
}
