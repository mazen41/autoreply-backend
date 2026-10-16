<?php

namespace App\Services;

use App\Models\Channel;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WooCommerceService
{
    public function createOrderForChannel(Channel $channel, array $checkout): array
    {
        $metadata = $channel->metadata ?? [];
        $storeUrl = rtrim((string) ($metadata['store_url'] ?? $channel->page_id), '/');
        $consumerKey = decrypt($metadata['consumer_key'] ?? '');
        $consumerSecret = decrypt($metadata['consumer_secret'] ?? '');
        $productId = $checkout['commerce_external_id'] ?? null;
        $productMetadata = $checkout['commerce_product_metadata'] ?? [];

        if ($storeUrl === '' || $consumerKey === '' || $consumerSecret === '' || !preg_match('/^\d+$/', (string) $productId)) {
            throw new \RuntimeException('WooCommerce connection or product identity is incomplete.');
        }

        $nameParts = preg_split('/\s+/', trim((string) ($checkout['full_name'] ?? '')), 2) ?: [];
        $address = trim((string) ($checkout['address'] ?? ''));
        $lineItem = [
            'product_id' => (int) $productId,
            'quantity' => max(1, (int) ($checkout['quantity'] ?? 1)),
        ];
        $variationId = $checkout['commerce_variant_id'] ?? null;
        if (($productMetadata['type'] ?? null) === 'variable') {
            $availableVariationIds = array_map(
                'strval',
                array_column($productMetadata['variations'] ?? [], 'id')
            );
            if (!$variationId || !in_array((string) $variationId, $availableVariationIds, true)) {
                throw new \RuntimeException('A valid WooCommerce product variation must be selected before ordering.');
            }
            $lineItem['variation_id'] = (int) $variationId;
        }

        $currency = $this->storeCurrency($storeUrl, $consumerKey, $consumerSecret);

        $email = trim((string) ($checkout['email'] ?? ''));
        $phone = (string) ($checkout['phone'] ?? '');

        $billing = [
            'first_name' => $nameParts[0] ?? '',
            'last_name' => $nameParts[1] ?? ($nameParts[0] ?? ''),
            'address_1' => $address,
        ];
        // WooCommerce validates billing.email strictly — an empty string
        // triggers HTTP 400 ("Invalid parameter(s): billing").  Only include
        // these optional fields when they carry a real value.
        if ($email !== '') {
            $billing['email'] = $email;
        }
        if ($phone !== '') {
            $billing['phone'] = $phone;
        }

        $payload = [
            'currency' => $currency,
            'set_paid' => false,
            'billing' => $billing,
            'shipping' => [
                'first_name' => $nameParts[0] ?? '',
                'last_name' => $nameParts[1] ?? ($nameParts[0] ?? ''),
                'address_1' => $address,
            ],
            'line_items' => [$lineItem],
        ];

        $response = Http::withBasicAuth($consumerKey, $consumerSecret)
            ->acceptJson()
            ->asJson()
            ->connectTimeout(5)
            ->timeout(20)
            ->post("{$storeUrl}/wp-json/wc/v3/orders", $payload);

        if (!$response->successful()) {
            // Capture the WooCommerce error response for diagnostics.
            // The body contains structured fields: code, message, data.params.
            $errorBody = $response->json() ?? [];
            $providerCode = is_string($errorBody['code'] ?? null) ? $errorBody['code'] : null;
            $providerMessage = is_string($errorBody['message'] ?? null) ? $errorBody['message'] : null;
            $providerParams = is_array($errorBody['data']['params'] ?? null)
                ? array_keys($errorBody['data']['params'])
                : null;

            Log::error('WOOCOMMERCE_ORDER_API_REJECTED', [
                'http_status'      => $response->status(),
                'provider_code'    => $providerCode,
                'provider_message' => $providerMessage ? OrderCheckoutService::maskSensitive($providerMessage) : null,
                'rejected_params'  => $providerParams,
                'product_id'       => (int) $productId,
            ]);

            $detail = $providerCode
                ? " [{$providerCode}]" . ($providerMessage ? ": {$providerMessage}" : '')
                : '';
            throw new \RuntimeException(
                'WooCommerce rejected order creation (HTTP ' . $response->status() . ').' . $detail
            );
        }

        $order = $response->json();
        if (empty($order['id'])) {
            throw new \RuntimeException('WooCommerce order response did not include an order ID.');
        }

        return ['data' => $order, 'id' => $order['id']];
    }

    /**
     * Get order by phone number
     */
    public function getOrderByPhone(array $channelMetadata, string $phone): ?array
    {
        try {
            $storeUrl = $channelMetadata['store_url'] ?? null;
            $consumerKey = decrypt($channelMetadata['consumer_key'] ?? '');
            $consumerSecret = decrypt($channelMetadata['consumer_secret'] ?? '');

            if (!$storeUrl || !$consumerKey || !$consumerSecret) {
                return null;
            }

            // Search for orders by billing phone directly
            $response = Http::withBasicAuth($consumerKey, $consumerSecret)
                ->get("{$storeUrl}/wp-json/wc/v3/orders", [
                    'billing_phone' => $phone,
                    'orderby' => 'date',
                    'order' => 'desc',
                    'per_page' => 1,
                ]);

            if (!$response->successful() || empty($response->json())) {
                return null;
            }

            return $response->json()[0];
        } catch (\Exception $e) {
            Log::error('WooCommerce order lookup failed', [
                'exception_type' => get_class($e),
            ]);
            return null;
        }
    }

    /**
     * Format order for AI context (same format as SallaService)
     */
    public function formatOrderForAI(array $order): string
    {
        $statusTranslations = [
            'pending' => 'قيد الانتظار',
            'processing' => 'قيد المعالجة',
            'completed' => 'مكتمل',
            'cancelled' => 'ملغى',
            'refunded' => 'مسترجع',
            'failed' => 'فشل',
        ];

        $status = $order['status'] ?? 'Unknown';
        $displayStatus = $statusTranslations[$status] ?? $status;

        $orderNumber = $order['number'] ?? $order['id'] ?? 'N/A';
        $total = $order['total'] ?? '0';
        $currency = $order['currency'] ?? 'USD';
        $dateCreated = $order['date_created'] ?? 'Not specified';

        $items = [];
        foreach ($order['line_items'] ?? [] as $item) {
            $items[] = ($item['name'] ?? 'Unknown') . ' x' . ($item['quantity'] ?? 1);
        }

        $billing = $order['billing'] ?? [];
        $customerName = trim(($billing['first_name'] ?? '') . ' ' . ($billing['last_name'] ?? ''));

        return "✅ ORDER DATA FOUND — use these details exactly:\n" .
               "• Order Number  : {$orderNumber}\n" .
               "• Status        : {$displayStatus}\n" .
               "• Total         : {$total} {$currency}\n" .
               "• Customer Name : {$customerName}\n" .
               "• Items         : " . implode(', ', $items) . "\n" .
               "• Date Created  : {$dateCreated}\n\n" .
               "Present these details in a clear, friendly format. intent = order_status\n\n";
    }
    /**
     * Current store currency from the WooCommerce store itself. Never guessed:
     * WooCommerce defaults to USD when currency is omitted, so a failed lookup
     * stops the order instead of recording it in the wrong currency.
     */
    private function storeCurrency(string $storeUrl, string $consumerKey, string $consumerSecret): string
    {
        $response = Http::withBasicAuth($consumerKey, $consumerSecret)
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout(15)
            ->get("{$storeUrl}/wp-json/wc/v3/data/currencies/current");

        $code = $response->successful() ? strtoupper((string) ($response->json('code') ?? '')) : '';
        if (!preg_match('/^[A-Z]{3}$/', $code)) {
            throw new \RuntimeException('WooCommerce store currency could not be read (HTTP ' . $response->status() . ').');
        }

        return $code;
    }
}
