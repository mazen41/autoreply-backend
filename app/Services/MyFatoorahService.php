<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;

class MyFatoorahService
{
    private string $apiKey;
    private string $baseUrl;
    private array  $defaultHeaders;

    public function __construct()
    {
        $this->apiKey  = config('services.myfatoorah.api_key');
        $this->baseUrl = rtrim(config('services.myfatoorah.base_url', 'https://api.myfatoorah.com'), '/');

        $this->defaultHeaders = [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
        ];
    }

    // ─── InitiatePayment ───────────────────────────────────────────────────────
    public function initiatePayment(float $invoiceAmount, string $currencyIso = 'SAR'): array
    {
        $payload = [
            'InvoiceAmount' => $invoiceAmount,
            'CurrencyIso'   => $currencyIso,
        ];

        Log::info('[MyFatoorah] InitiatePayment request', [
            'url'     => "{$this->baseUrl}/v2/InitiatePayment",
            'payload' => $payload,
            'key_prefix' => substr($this->apiKey, 0, 10) . '...',
        ]);

        $response = Http::withHeaders($this->defaultHeaders)
            ->post("{$this->baseUrl}/v2/InitiatePayment", $payload);

        Log::info('[MyFatoorah] InitiatePayment response', [
            'status' => $response->status(),
            'body'   => $response->body(),
        ]);

        if ($response->failed()) {
            throw new Exception('MyFatoorah InitiatePayment failed: ' . $response->body());
        }

        $data = $response->json();

        if (! ($data['IsSuccess'] ?? false)) {
            throw new Exception('MyFatoorah InitiatePayment error: ' . ($data['Message'] ?? 'Unknown error'));
        }

        return $data['Data'];
    }

    // ─── ExecutePayment ────────────────────────────────────────────────────────
    public function executePayment(array $payload): array
    {
        $payload['CallBackUrl'] = $payload['CallBackUrl'] ?? 'https://nazbiz.io/payment/callback';
        $payload['ErrorUrl']    = $payload['ErrorUrl']    ?? 'https://nazbiz.io/payment/error';

        Log::info('[MyFatoorah] ExecutePayment request', ['payload' => $payload]);

        $response = Http::withHeaders($this->defaultHeaders)
            ->post("{$this->baseUrl}/v2/ExecutePayment", $payload);

        Log::info('[MyFatoorah] ExecutePayment response', [
            'status' => $response->status(),
            'body'   => $response->body(),
        ]);

        if ($response->failed()) {
            throw new Exception('MyFatoorah ExecutePayment failed: ' . $response->body());
        }

        $data = $response->json();

        if (! ($data['IsSuccess'] ?? false)) {
            throw new Exception('MyFatoorah ExecutePayment error: ' . ($data['Message'] ?? 'Unknown error'));
        }

        return $data['Data'];
    }

    // ─── GetPaymentStatus ──────────────────────────────────────────────────────
    public function getPaymentStatus(string $key, string $keyType = 'PaymentId'): array
    {
        $response = Http::withHeaders($this->defaultHeaders)
            ->post("{$this->baseUrl}/v2/GetPaymentStatus", [
                'Key'     => $key,
                'KeyType' => $keyType,
            ]);

        if ($response->failed()) {
            Log::error('[MyFatoorah] GetPaymentStatus failed', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            throw new Exception('MyFatoorah GetPaymentStatus failed: ' . $response->body());
        }

        $data = $response->json();

        if (! ($data['IsSuccess'] ?? false)) {
            throw new Exception('MyFatoorah GetPaymentStatus error: ' . ($data['Message'] ?? 'Unknown error'));
        }

        return $data['Data'];
    }

    // ─── Webhook Signature Verification ───────────────────────────────────────
    public function verifyWebhookSignature(string $rawBody, string $signature): bool
    {
        $secret = config('services.myfatoorah.webhook_secret');

        if (empty($secret)) {
            Log::warning('[MyFatoorah] Webhook secret not configured – skipping signature check');
            return false;
        }

        $expected = hash_hmac('sha256', $rawBody, $secret);
        return hash_equals($expected, $signature);
    }
}
