<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PaymobService
{
    private string $secretKey;
    private string $publicKey;
    private string $hmacKey;
    private string $currency;
    private string $apiBase;
    private ?string $integrationId;

    public function __construct()
    {
        $this->secretKey     = config('services.paymob.secret_key');
        $this->publicKey     = config('services.paymob.public_key');
        $this->hmacKey       = config('services.paymob.hmac_key');
        $this->currency      = config('services.paymob.currency', 'EGP');
        $this->apiBase       = config('services.paymob.api_base', 'https://accept.paymob.com');
        $this->integrationId = config('services.paymob.integration_id');
    }

    /**
     * Create a Paymob payment intention.
     *
     * Returns an array with:
     *   - client_secret  (string)  — used to open the unified checkout
     *   - checkout_url   (string)  — full URL to redirect the user to
     *   - order_id       (string)  — Paymob's order identifier
     *
     * @param  int    $amountCents      Amount in smallest currency unit (piastres for EGP)
     * @param  array  $billingData      Shopper billing info
     * @param  array  $metadata         Display-only info (package_name, description) — NOT reliably echoed back by Paymob, do not use for identifying the order later
     * @param  string $redirectUrl      Where to send the user after payment
     * @param  string $specialReference Our own PaymentIntent row ID — Paymob reliably echoes this back as `merchant_order_id` on the transaction, so use THIS (not metadata/extras) to look the order back up
     * @return array
     */
    public function createIntention(
        int    $amountCents,
        array  $billingData,
        array  $metadata = [],
        string $redirectUrl = '',
        string $specialReference = ''
    ): array {
        if (!$this->integrationId) {
            throw new \RuntimeException(
                'Paymob integration_id is not configured. Set PAYMOB_CARD_INTEGRATION_ID '
                . 'in .env to the numeric ID from Merchant Dashboard → Developers → Payment Integrations.'
            );
        }

        $payload = [
            'amount'          => $amountCents,
            'currency'        => $this->currency,
            'payment_methods' => [(int) $this->integrationId], // Paymob integration ID(s), not literal "card"
            'items'           => [
                [
                    'name'        => $metadata['package_name'] ?? 'Subscription',
                    'amount'      => $amountCents,
                    'description' => $metadata['description'] ?? 'nazbiz subscription',
                    'quantity'    => 1,
                ],
            ],
            'billing_data'    => $billingData,
            'customer'        => [
                'first_name'  => $billingData['first_name'] ?? 'Customer',
                'last_name'   => $billingData['last_name']  ?? '',
                'email'       => $billingData['email']      ?? '',
            ],
            'extras'          => $metadata,
        ];

        if ($specialReference) {
            $payload['special_reference'] = $specialReference;
        }

        if ($redirectUrl) {
            // Paymob's field is "redirection_url" — NOT "redirect_url".
            // Sending the wrong key means Paymob silently ignores it and
            // strands the customer on its own generic post_pay results page
            // instead of sending them back to our callback route, so the
            // subscription never gets created even on a successful payment.
            $payload['redirection_url'] = $redirectUrl;
            // Also register the same URL as a server-to-server webhook so the
            // transaction is recorded even if the customer closes the tab
            // before the browser redirect completes.
            $payload['notification_url'] = config('app.url') . '/api/payments/webhook';
        }

        Log::info('Paymob: Creating intention', ['amount' => $amountCents, 'currency' => $this->currency]);

        $response = Http::withToken($this->secretKey)
            ->post("{$this->apiBase}/v1/intention/", $payload);

        if (!$response->successful()) {
            Log::error('Paymob: Intention creation failed', [
                'status'   => $response->status(),
                'response' => $response->body(),
            ]);

            throw new \RuntimeException('Paymob intention creation failed: ' . $response->body());
        }

        $data = $response->json();

        $clientSecret = $data['client_secret'] ?? null;
        $orderId      = $data['id'] ?? null;

        if (!$clientSecret) {
            Log::error('Paymob: No client_secret in response', ['data' => $data]);
            throw new \RuntimeException('Paymob did not return a client_secret');
        }

        $checkoutUrl = "{$this->apiBase}/unifiedcheckout/?publicKey={$this->publicKey}&clientSecret={$clientSecret}";

        Log::info('Paymob: Intention created', ['order_id' => $orderId, 'checkout_url' => $checkoutUrl]);

        return [
            'client_secret' => $clientSecret,
            'checkout_url'  => $checkoutUrl,
            'order_id'      => $orderId,
        ];
    }

    /**
     * Verify the HMAC signature that Paymob sends on webhook callbacks.
     *
     * Paymob concatenates specific fields in a fixed order and signs
     * them with the HMAC key using SHA-512.
     *
     * @param  array  $payload   The full POST payload from Paymob
     * @param  string $received  The hmac value from the payload
     * @return bool
     */
    public function verifyHmac(array $payload, string $received): bool
    {
        // Paymob HMAC string fields (order matters)
        $obj = $payload['obj'] ?? [];

        $fields = [
            'amount_cents'       => $obj['amount_cents']       ?? '',
            'created_at'         => $obj['created_at']         ?? '',
            'currency'           => $obj['currency']           ?? '',
            'error_occured'      => isset($obj['error_occured']) ? ($obj['error_occured'] ? 'true' : 'false') : '',
            'has_parent_transaction' => isset($obj['has_parent_transaction']) ? ($obj['has_parent_transaction'] ? 'true' : 'false') : '',
            'id'                 => $obj['id']                 ?? '',
            'integration_id'     => $obj['integration_id']    ?? '',
            'is_3d_secure'       => isset($obj['is_3d_secure']) ? ($obj['is_3d_secure'] ? 'true' : 'false') : '',
            'is_auth'            => isset($obj['is_auth']) ? ($obj['is_auth'] ? 'true' : 'false') : '',
            'is_capture'         => isset($obj['is_capture']) ? ($obj['is_capture'] ? 'true' : 'false') : '',
            'is_refunded'        => isset($obj['is_refunded']) ? ($obj['is_refunded'] ? 'true' : 'false') : '',
            'is_standalone_payment' => isset($obj['is_standalone_payment']) ? ($obj['is_standalone_payment'] ? 'true' : 'false') : '',
            'is_voided'          => isset($obj['is_voided']) ? ($obj['is_voided'] ? 'true' : 'false') : '',
            'order.id'           => $obj['order']['id']        ?? '',
            'owner'              => $obj['owner']              ?? '',
            'pending'            => isset($obj['pending']) ? ($obj['pending'] ? 'true' : 'false') : '',
            'source_data.pan'    => $obj['source_data']['pan'] ?? '',
            'source_data.sub_type' => $obj['source_data']['sub_type'] ?? '',
            'source_data.type'   => $obj['source_data']['type'] ?? '',
            'success'            => isset($obj['success']) ? ($obj['success'] ? 'true' : 'false') : '',
        ];

        $concatenated = implode('', array_values($fields));
        $expected     = hash_hmac('sha512', $concatenated, $this->hmacKey);

        return hash_equals($expected, strtolower($received));
    }

    /**
     * Fetch a transaction by its ID from Paymob's API.
     *
     * @param  int|string $transactionId
     * @return array|null
     */
    public function getTransaction($transactionId): ?array
    {
        $response = Http::withToken($this->secretKey)
            ->get("{$this->apiBase}/api/acceptance/transactions/{$transactionId}");

        if (!$response->successful()) {
            Log::error('Paymob: Failed to fetch transaction', ['id' => $transactionId]);
            return null;
        }

        return $response->json();
    }
}
