<?php

namespace App\Http\Controllers;

use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WooCommerceController extends Controller
{
    public function connect(Request $request)
    {
        $request->validate([
            'store_url' => 'required|url',
            'consumer_key' => 'required|string',
            'consumer_secret' => 'required|string',
        ]);

        $storeUrl = rtrim($request->store_url, '/');
        if (!str_starts_with($storeUrl, 'https://')) {
            $storeUrl = 'https://' . $storeUrl;
        }
        
        $consumerKey = $request->consumer_key;
        $consumerSecret = $request->consumer_secret;
        $userId = auth()->id();

        try {
            // Verify credentials by calling WooCommerce API with Basic Auth
            $response = Http::timeout(10)->withBasicAuth($consumerKey, $consumerSecret)
                ->get("{$storeUrl}/wp-json/wc/v3/system_status");

            if (!$response->successful()) {
                Log::error('WooCommerce credentials verification failed', [
                    'user_id' => $userId,
                    'store_url' => $storeUrl,
                    'status' => $response->status(),
                    'body' => $response->json(),
                ]);
                return response()->json(['error' => 'Invalid WooCommerce credentials'], 422);
            }

            $systemStatus = $response->json();
            $storeName = $systemStatus['settings']['store_name'] ?? $storeUrl;
            $environment = $systemStatus['environment']['version'] ?? 'Unknown';

            // Save channel with encrypted credentials in metadata
            $businessProfile = \App\Models\BusinessProfile::where('user_id', $userId)->first();

            $channel = Channel::updateOrCreate(
                [
                    'user_id' => $userId,
                    'type' => 'woocommerce',
                    'page_id' => $storeUrl,
                ],
                [
                    'page_name' => $storeName,
                    'status' => 'connected',
                    'connected_at' => now(),
                    'business_id' => $businessProfile ? $businessProfile->id : null,
                    'metadata' => [
                        'store_url' => $storeUrl,
                        'consumer_key' => encrypt($consumerKey),
                        'consumer_secret' => encrypt($consumerSecret),
                        'environment' => $environment,
                    ],
                ]
            );

            // Register webhooks for real-time synchronization
            $this->registerWebhooks($storeUrl, $consumerKey, $consumerSecret);

            Log::info('WooCommerce channel connected', [
                'user_id' => $userId,
                'store_url' => $storeUrl,
                'store_name' => $storeName,
                'channel_id' => $channel->id,
            ]);

            return response()->json([
                'success' => true,
                'channel' => [
                    'id' => $channel->id,
                    'name' => $storeName,
                    'type' => 'woocommerce',
                    'status' => 'connected',
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('WooCommerce connection error', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
            return response()->json(['error' => 'Failed to connect WooCommerce store'], 500);
        }
    }

    public function getOrders(Request $request)
    {
        $phone = $request->query('phone');
        if (!$phone) {
            return response()->json(['error' => 'Phone number is required'], 400);
        }

        $channel = Channel::where('type', 'woocommerce')
            ->where('user_id', auth()->id())
            ->where('status', 'connected')
            ->first();

        if (!$channel) {
            return response()->json(['error' => 'WooCommerce channel not connected'], 404);
        }

        try {
            $metadata = $channel->metadata;
            $storeUrl = $metadata['store_url'] ?? $channel->page_id;
            $consumerKey = decrypt($metadata['consumer_key']);
            $consumerSecret = decrypt($metadata['consumer_secret']);

            // Search for orders by billing phone directly
            $ordersResponse = Http::withBasicAuth($consumerKey, $consumerSecret)
                ->get("{$storeUrl}/wp-json/wc/v3/orders", [
                    'billing_phone' => $phone,
                    'orderby' => 'date',
                    'order' => 'desc',
                    'per_page' => 1,
                ]);

            if (!$ordersResponse->successful() || empty($ordersResponse->json())) {
                return response()->json(['order' => null]);
            }

            $order = $ordersResponse->json()[0];

            // Format order data for AI
            $formattedOrder = $this->formatOrderForAI($order);

            return response()->json([
                'success' => true,
                'order' => $formattedOrder,
            ]);

        } catch (\Exception $e) {
            Log::error('WooCommerce order lookup error', [
                'error' => $e->getMessage(),
                'phone' => $phone,
            ]);
            return response()->json(['error' => 'Failed to fetch orders'], 500);
        }
    }

    private function formatOrderForAI(array $order): array
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

        $items = [];
        foreach ($order['line_items'] ?? [] as $item) {
            $items[] = [
                'name' => $item['name'] ?? 'Unknown',
                'quantity' => $item['quantity'] ?? 1,
                'price' => ($item['price'] ?? '0') . ' ' . ($order['currency'] ?? 'USD'),
            ];
        }

        $billing = $order['billing'] ?? [];
        $shipping = $order['shipping'] ?? [];
        
        $shippingAddress = trim(($billing['address_1'] ?? '') . ' ' . 
                             ($billing['address_2'] ?? '') . ' ' . 
                             ($billing['city'] ?? '') . ' ' . 
                             ($billing['state'] ?? '') . ' ' . 
                             ($billing['postcode'] ?? '') . ' ' . 
                             ($billing['country'] ?? ''));

        return [
            'order_id' => $order['number'] ?? $order['id'] ?? 'N/A',
            'status' => $displayStatus,
            'total' => ($order['total'] ?? '0') . ' ' . ($order['currency'] ?? 'USD'),
            'items' => $items,
            'customer_name' => trim(($billing['first_name'] ?? '') . ' ' . ($billing['last_name'] ?? '')),
            'shipping_address' => $shippingAddress ?: 'Not specified',
            'created_at' => $order['date_created'] ?? 'Not specified',
            'tracking_number' => null,
        ];
    }
}