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
        $this->apiKey   = config('services.myfatoorah.api_key');
        $this->baseUrl  = rtrim(config('services.myfatoorah.base_url', 'https://api.myfatoorah.com'), '/');

        $this->defaultHeaders = [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
        ];
    }

    // ─── InitiatePayment ───────────────────────────────────────────────────────
    /**
     * Calls MyFatoorah's /v2/InitiatePayment endpoint to discover the
     * available payment methods for a given invoice amount & currency.
     *
     * @param  float  $invoiceAmount  e.g. 99.00
     * @param  string $currencyIso    e.g. "SAR"
     * @return array  ['PaymentMethods' => [...], ...]
     * @throws Exception
     */
    public function initiatePayment(float $invoiceAmount, string $currencyIso = 'SAR'): array
    {
        $response = Http::withHeaders($this->defaultHeaders)
            ->post("{$this->baseUrl}/v2/InitiatePayment", [
                'InvoiceAmount' => $invoiceAmount,
                'CurrencyIso'   => $currencyIso,
            ]);

        if ($response->failed()) {
            Log::error('[MyFatoorah] InitiatePayment failed', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            throw new Exception('MyFatoorah InitiatePayment failed: ' . $response->body());
        }

        $data = $response->json();

        if (! ($data['IsSuccess'] ?? false)) {
            throw new Exception('MyFatoorah InitiatePayment error: ' . ($data['Message'] ?? 'Unknown error'));
        }

        return $data['Data'];
    }

    // ─── ExecutePayment ────────────────────────────────────────────────────────
    /**
     * Calls /v2/ExecutePayment to create an invoice and get the redirect URL
     * for the MyFatoorah hosted payment page.
     *
     * @param  array $payload  {
     *   PaymentMethodId  int    (from InitiatePayment; 0 = let customer choose)
     *   InvoiceValue     float
     *   CustomerName     string
     *   CustomerEmail    string
     *   CustomerMobile   string (optional)
     *   DisplayCurrencyIso string e.g. "SAR"
     *   MobileCountryCode  string e.g. "+966"
     *   Language         string "EN"|"AR"
     *   CallBackUrl      string
     *   ErrorUrl         string
     *   UserDefinedField string (optional – your internal reference)
     * }
     * @return array  ['InvoiceId' => ..., 'IsDirectPayment' => ..., 'PaymentURL' => ...]
     * @throws Exception
     */
    public function executePayment(array $payload): array
    {
        // Ensure redirect URLs always point to nazbiz.io
        $payload['CallBackUrl'] = $payload['CallBackUrl'] ?? 'https://nazbiz.io/payment/callback';
        $payload['ErrorUrl']    = $payload['ErrorUrl']    ?? 'https://nazbiz.io/payment/error';

        $response = Http::withHeaders($this->defaultHeaders)
            ->post("{$this->baseUrl}/v2/ExecutePayment", $payload);

        if ($response->failed()) {
            Log::error('[MyFatoorah] ExecutePayment failed', [
                'status'  => $response->status(),
                'body'    => $response->body(),
                'payload' => $payload,
            ]);
            throw new Exception('MyFatoorah ExecutePayment failed: ' . $response->body());
        }

        $data = $response->json();

        if (! ($data['IsSuccess'] ?? false)) {
            throw new Exception('MyFatoorah ExecutePayment error: ' . ($data['Message'] ?? 'Unknown error'));
        }

        return $data['Data'];
    }

    // ─── GetPaymentStatus ──────────────────────────────────────────────────────
    /**
     * Calls /v2/GetPaymentStatus to verify a payment after redirect or webhook.
     *
     * @param  string $key       The PaymentId returned in the callback query string
     * @param  string $keyType   "PaymentId" (default) | "InvoiceId" | "InvoiceValue"
     * @return array  Full invoice/transaction data from MyFatoorah
     * @throws Exception
     */
    public function getPaymentStatus(string $key, string $keyType = 'PaymentId'): array
    {
        $response = Http::withHeaders($this->defaultHeaders)
            ->post("{$this->baseUrl}/v2/GetPaymentStatus", [
                'Key'     => $key,
                'KeyType' => $keyType,
            ]);

        if ($response->failed()) {
            Log::error('[MyFatoorah] GetPaymentStatus failed', [
                'status'  => $response->status(),
                'body'    => $response->body(),
                'key'     => $key,
                'keyType' => $keyType,
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
    /**
     * Verifies the HMAC-SHA256 signature sent in the MyFatoorah-Signature header.
     *
     * @param  string $rawBody    Raw request body (file_get_contents('php://input'))
     * @param  string $signature  Value of MyFatoorah-Signature header
     * @return bool
     */
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
