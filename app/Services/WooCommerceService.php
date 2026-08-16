<?php

namespace App\Services;

use App\Models\Channel;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WooCommerceService
{
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