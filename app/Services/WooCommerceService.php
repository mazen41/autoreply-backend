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

        $payload = [
            'payment_method' => '',
            'payment_method_title' => '',
            'set_paid' => false,
            'billing' => [
                'first_name' => $nameParts[0] ?? '',
                'last_name' => $nameParts[1] ?? ($nameParts[0] ?? ''),
                'address_1' => $address,
                'phone' => (string) ($checkout['phone'] ?? ''),
                'email' => (string) ($checkout['email'] ?? ''),
            ],
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
            throw new \RuntimeException('WooCommerce rejected order creation (HTTP ' . $response->status() . ').');
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
                'error' => $e->getMessage(),
                'phone' => $phone,
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
}
