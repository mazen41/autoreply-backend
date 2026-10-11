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
            'amount' => $invoiceAmount,
            'currency' => $currencyIso,
        ]);

        $response = Http::withHeaders($this->defaultHeaders)
            ->post("{$this->baseUrl}/v2/InitiatePayment", $payload);

        Log::info('[MyFatoorah] InitiatePayment response', [
            'status' => $response->status(),
        ]);

        if ($response->failed()) {
            throw new Exception('MyFatoorah InitiatePayment failed with HTTP status ' . $response->status());
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

        Log::info('[MyFatoorah] ExecutePayment request', ['field_count' => count($payload)]);

        $response = Http::withHeaders($this->defaultHeaders)
            ->post("{$this->baseUrl}/v2/ExecutePayment", $payload);

        Log::info('[MyFatoorah] ExecutePayment response', [
            'status' => $response->status(),
        ]);

        if ($response->failed()) {
            throw new Exception('MyFatoorah ExecutePayment failed with HTTP status ' . $response->status());
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
            ]);
            throw new Exception('MyFatoorah GetPaymentStatus failed with HTTP status ' . $response->status());
        }

        $data = $response->json();

        if (! ($data['IsSuccess'] ?? false)) {
            throw new Exception('MyFatoorah GetPaymentStatus error: ' . ($data['Message'] ?? 'Unknown error'));
        }

        return $data['Data'];
    }

    public function cancelRecurringPayment(string $recurringId): void
    {
        $response = Http::withHeaders($this->defaultHeaders)
            ->post("{$this->baseUrl}/v2/CancelRecurringPayment?recurringId=" . urlencode($recurringId));

        if ($response->failed() || !($response->json('IsSuccess') ?? false) || !($response->json('Data') ?? false)) {
            throw new Exception('MyFatoorah recurring cancellation failed with HTTP status ' . $response->status());
        }
    }

    public function verifyRecurringWebhookSignature(array $payload, string $signature): bool
    {
        $secret = config('services.myfatoorah.webhook_secret');
        $recurring = $payload['Data']['Recurring'] ?? [];
        if (empty($secret) || empty($signature) || !isset($recurring['Id'], $recurring['Status'], $recurring['InitialInvoiceId'])) {
            return false;
        }

        $signedData = 'Recurring.Id=' . $recurring['Id']
            . ',Recurring.Status=' . $recurring['Status']
            . ',Recurring.InitialInvoiceId=' . $recurring['InitialInvoiceId'];

        return hash_equals(hash_hmac('sha256', $signedData, $secret), $signature);
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
