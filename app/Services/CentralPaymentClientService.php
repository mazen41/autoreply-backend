<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class CentralPaymentClientService
{
    public function createHostedPayment(string $orderId, string $idempotencyKey): array
    {
        $clientId = (string) config('services.central_payments.client_id');
        $apiKey = (string) config('services.central_payments.api_key');
        $secret = (string) config('services.central_payments.hmac_secret');
        $baseUrl = rtrim((string) config('services.central_payments.api_url', ''), '/');
        if ($clientId === '' || $apiKey === '' || $secret === '' || $baseUrl === '') {
            throw new RuntimeException('Central payment client credentials are not configured.');
        }

        $path = '/api/v1/payments';
        $body = json_encode([
            'external_order_id' => $orderId,
            'idempotency_key' => $idempotencyKey,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $timestamp = (string) now()->timestamp;
        $nonce = Str::random(40);
        $canonical = implode("\n", ['POST', $path, $timestamp, $nonce, hash('sha256', $body)]);
        $signature = hash_hmac('sha256', $canonical, $secret);

        $response = Http::timeout(20)
            ->withHeaders([
                'Authorization' => 'Bearer ' . $apiKey,
                'X-Payment-Client' => $clientId,
                'X-Payment-Timestamp' => $timestamp,
                'X-Payment-Nonce' => $nonce,
                'X-Payment-Signature' => $signature,
            ])
            ->withBody($body, 'application/json')
            ->post($baseUrl . $path);

        if (!$response->successful() || !is_string($response->json('checkout_url'))) {
            throw new RuntimeException('Central payment service did not return a checkout URL.');
        }

        return [
            'payment_url' => $response->json('checkout_url'),
            'central_payment_id' => $response->json('payment_id'),
            'status' => $response->json('status'),
        ];
    }

    public function getPayment(string $paymentUuid): array
    {
        $path = '/api/v1/payments/' . rawurlencode($paymentUuid);
        $query = [
            'client_id' => (string) config('services.central_payments.client_id'),
            'api_key' => (string) config('services.central_payments.api_key'),
            'hmac_secret' => (string) config('services.central_payments.hmac_secret'),
            'api_url' => rtrim((string) config('services.central_payments.api_url', ''), '/'),
        ];
        if (in_array('', $query, true)) throw new RuntimeException('Central payment client credentials are not configured.');

        $timestamp = (string) now()->timestamp;
        $nonce = Str::random(40);
        $canonical = implode("\n", ['GET', $path, $timestamp, $nonce, hash('sha256', '')]);
        $response = Http::timeout(12)->withHeaders([
            'Authorization' => 'Bearer ' . $query['api_key'],
            'X-Payment-Client' => $query['client_id'],
            'X-Payment-Timestamp' => $timestamp,
            'X-Payment-Nonce' => $nonce,
            'X-Payment-Signature' => hash_hmac('sha256', $canonical, $query['hmac_secret']),
        ])->get($query['api_url'] . $path);

        if (!$response->successful()) throw new RuntimeException('Central payment status could not be retrieved.');
        return $response->json();
    }
}
