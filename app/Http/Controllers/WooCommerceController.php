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
        $consumerKey = $request->consumer_key;
        $consumerSecret = $request->consumer_secret;
        $userId = auth()->id();

        try {
            // Verify credentials by calling WooCommerce API
            $response = Http::timeout(10)->get("{$storeUrl}/wp-json/wc/v3/system_status", [
                'consumer_key' => $consumerKey,
                'consumer_secret' => $consumerSecret,
            ]);

            if (!$response->successful()) {
                Log::error('WooCommerce credentials verification failed', [
                    'user_id' => $userId,
                    'store_url' => $storeUrl,
                    'status' => $response->status(),
                    'body' => $response->json(),
                ]);
                return response()->json(['error' => 'Invalid WooCommerce credentials'], 400);
            }

            $systemStatus = $response->json();
            $storeName = $systemStatus['settings']['store_name'] ?? 'WooCommerce Store';
            $environment = $systemStatus['environment']['version'] ?? 'Unknown';

            // Save channel
            $businessProfile = \App\Models\BusinessProfile::where('user_id', $userId)->first();

            $channel = Channel::updateOrCreate(
                [
                    'user_id' => $userId,
                    'type' => 'woocommerce',
                    'page_id' => $storeUrl,
                ],
                [
                    'page_name' => $storeName,
                    'access_token' => $consumerKey,
                    'refresh_token' => $consumerSecret, // Store secret in refresh_token field
                    'status' => 'connected',
                    'connected_at' => now(),
                    'business_id' => $businessProfile ? $businessProfile->id : null,
                    'metadata' => [
                        'environment' => $environment,
                        'store_url' => $storeUrl,
                    ],
                ]
            );

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
                    'type' => 'woocommerce',
                    'store_name' => $storeName,
                    'store_url' => $storeUrl,
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
            $storeUrl = $channel->page_id;
            $consumerKey = $channel->access_token;
            $consumerSecret = $channel->refresh_token;

            // Search for customer by phone
            $customerResponse = Http::get("{$storeUrl}/wp-json/wc/v3/customers", [
                'consumer_key' => $consumerKey,
                'consumer_secret' => $consumerSecret,
                'phone' => $phone,
            ]);

            if (!$customerResponse->successful() || empty($customerResponse->json())) {
                return response()->json(['error' => 'No customer found with this phone'], 404);
            }

            $customer = $customerResponse->json()[0];

            // Get customer orders
            $ordersResponse = Http::get("{$storeUrl}/wp-json/wc/v3/orders", [
                'consumer_key' => $consumerKey,
                'consumer_secret' => $consumerSecret,
                'customer' => $customer['id'],
                'status' => 'any',
                'orderby' => 'date',
                'order' => 'desc',
                'per_page' => 1,
            ]);

            if (!$ordersResponse->successful() || empty($ordersResponse->json())) {
                return response()->json(['error' => 'No orders found for this customer'], 404);
            }

            $order = $ordersResponse->json()[0];

            // Format order data for AI (same pattern as Salla)
            $formattedOrder = $this->formatOrderForAI($order);

            return response()->json([
                'success' => true,
                'order' => $formattedOrder,
                'raw_order' => $order,
            ]);

        } catch (\Exception $e) {
            Log::error('WooCommerce order lookup error', [
                'error' => $e->getMessage(),
                'phone' => $phone,
            ]);
            return response()->json(['error' => 'Failed to fetch orders'], 500);
        }
    }

    private function formatOrderForAI(array $order): string
    {
        $orderNumber = $order['number'] ?? $order['id'] ?? 'N/A';
        $status = $order['status'] ?? 'Unknown';
        $total = $order['total'] ?? '0';
        $currency = $order['currency'] ?? 'USD';
        $dateCreated = $order['date_created'] ?? 'Not specified';

        $products = [];
        foreach ($order['line_items'] ?? [] as $item) {
            $products[] = ($item['name'] ?? 'Unknown') . ' x' . ($item['quantity'] ?? 1);
        }

        return "Order #{$orderNumber}\nStatus: {$status}\nTotal: {$total} {$currency}\n" .
               "Products: " . implode(', ', $products) . "\n" .
               "Date Created: {$dateCreated}";
    }
}