<?php

namespace App\Services;

use App\Models\PaymentClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class CentralPaymentOrderVerifier
{
    /** Ask the originating backend to look up and price its own pending order. */
    public function verify(PaymentClient $client, string $externalOrderId): array
    {
        $path = '/api/internal/payments/verify';
        $payload = ['external_order_id' => $externalOrderId];
        $rawBody = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $timestamp = (string) now()->timestamp;
        $nonce = Str::random(40);
        $canonical = implode("\n", [
            'POST', $path, $timestamp, $nonce, hash('sha256', $rawBody),
        ]);
        $signature = hash_hmac('sha256', $canonical, $client->hmac_secret);

        $response = Http::timeout(12)
            ->withHeaders([
                'X-Central-Payment-Client' => $client->public_id,
                'X-Central-Payment-Timestamp' => $timestamp,
                'X-Central-Payment-Nonce' => $nonce,
                'X-Central-Payment-Signature' => $signature,
            ])
            ->withBody($rawBody, 'application/json')
            ->post($client->backend_url . $path);

        if (!$response->successful() || $response->json('verified') !== true) {
            throw new RuntimeException('Origin website did not verify the order.');
        }

        $order = $response->json('order');
        if (!is_array($order)
            || (string) ($order['external_order_id'] ?? '') !== $externalOrderId
            || !is_numeric($order['amount'] ?? null)
            || (float) $order['amount'] <= 0
            || !preg_match('/^[A-Z]{3}$/', strtoupper((string) ($order['currency'] ?? '')))) {
            throw new RuntimeException('Origin website returned an invalid verified order.');
        }

        $order['amount'] = number_format((float) $order['amount'], 2, '.', '');
        $order['currency'] = strtoupper((string) $order['currency']);

        return $order;
    }
}
