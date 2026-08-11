<?php

namespace App\Http\Controllers;

use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ShopifyController extends Controller
{
    public function connect(Request $request)
    {
        $shopDomain = $request->query('shop');
        if (!$shopDomain) {
            return response()->json(['error' => 'Shop domain is required'], 400);
        }

        $token = $request->query('token');
        $accessToken = \Laravel\Sanctum\PersonalAccessToken::findToken($token);
        if (!$accessToken) {
            return redirect(env('FRONTEND_URL') . '/dashboard/channels?error=unauthorized');
        }
        $user = $accessToken->tokenable;
        $state = $user->id . ':' . $request->query('redirect', 'dashboard');

        $apiKey = env('SHOPIFY_API_KEY');
        $redirectUri = env('SHOPIFY_REDIRECT_URI');

        $scopes = implode(',', [
            'read_products',
            'read_orders',
            'read_customers',
            'read_content',
        ]);

        $installUrl = "https://{$shopDomain}/admin/oauth/authorize?" . http_build_query([
            'client_id' => $apiKey,
            'scope' => $scopes,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'state' => $state,
        ]);

        return redirect($installUrl);
    }

    public function callback(Request $request)
    {
        Log::info('=== SHOPIFY CALLBACK START ===');
        Log::info('All request params', $request->all());

        $code = $request->get('code');
        $shop = $request->get('shop');
        $stateParts = explode(':', $request->get('state') ?? '');
        $userId = $stateParts[0] ?? null;
        $error = $request->get('error');

        if ($error || !$code) {
            Log::error('Shopify OAuth denied or no code', ['error' => $error]);
            return redirect(env('FRONTEND_URL') . '/dashboard/channels?error=shopify_denied');
        }

        if (!$userId) {
            Log::error('No user ID in Shopify state');
            return redirect(env('FRONTEND_URL') . '/dashboard/channels?error=session_expired');
        }

        $apiKey = env('SHOPIFY_API_KEY');
        $apiSecret = env('SHOPIFY_API_SECRET');
        $redirectUri = env('SHOPIFY_REDIRECT_URI');

        try {
            // Exchange code for access token
            $tokenResponse = Http::asForm()->post("https://{$shop}/admin/oauth/access_token", [
                'client_id' => $apiKey,
                'client_secret' => $apiSecret,
                'code' => $code,
                'grant_type' => 'authorization_code',
                'redirect_uri' => $redirectUri,
            ]);

            if (!$tokenResponse->successful()) {
                Log::error('Shopify token exchange failed', [
                    'status' => $tokenResponse->status(),
                    'body' => $tokenResponse->json(),
                ]);
                return redirect(env('FRONTEND_URL') . '/dashboard/channels?error=token_failed');
            }

            $tokenData = $tokenResponse->json();
            $accessToken = $tokenData['access_token'] ?? null;

            if (!$accessToken) {
                Log::error('No access token in Shopify response');
                return redirect(env('FRONTEND_URL') . '/dashboard/channels?error=token_failed');
            }

            // Get shop info
            $shopResponse = Http::withToken($accessToken)
                ->get("https://{$shop}/admin/api/2024-01/shop.json");

            if (!$shopResponse->successful()) {
                Log::error('Failed to get Shopify shop info', [
                    'status' => $shopResponse->status(),
                    'body' => $shopResponse->json(),
                ]);
                return redirect(env('FRONTEND_URL') . '/dashboard/channels?error=shop_info_failed');
            }

            $shopInfo = $shopResponse->json();
            $shopName = $shopInfo['shop']['name'] ?? $shop;

            // Save channel
            $businessProfile = \App\Models\BusinessProfile::where('user_id', $userId)->first();

            $channel = Channel::updateOrCreate(
                [
                    'user_id' => $userId,
                    'type' => 'shopify',
                    'page_id' => $shop,
                ],
                [
                    'page_name' => $shopName,
                    'access_token' => $accessToken,
                    'status' => 'connected',
                    'connected_at' => now(),
                    'business_id' => $businessProfile ? $businessProfile->id : null,
                    'metadata' => [
                        'shop_domain' => $shop,
                        'shop_name' => $shopName,
                    ],
                ]
            );

            // Register webhook for order updates
            $this->registerWebhook($shop, $accessToken);

            Log::info('Shopify channel connected', [
                'user_id' => $userId,
                'shop' => $shop,
                'shop_name' => $shopName,
                'channel_id' => $channel->id,
            ]);

            return redirect(env('FRONTEND_URL') . '/dashboard/channels?success=shopify_connected');

        } catch (\Exception $e) {
            Log::error('Shopify connection error', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return redirect(env('FRONTEND_URL') . '/dashboard/channels?error=connection_failed');
        }
    }

    public function getOrders(Request $request)
    {
        $phone = $request->query('phone');
        if (!$phone) {
            return response()->json(['error' => 'Phone number is required'], 400);
        }

        $channel = Channel::where('type', 'shopify')
            ->where('user_id', auth()->id())
            ->where('status', 'connected')
            ->first();

        if (!$channel) {
            return response()->json(['error' => 'Shopify channel not connected'], 404);
        }

        try {
            $shop = $channel->page_id;
            $accessToken = $channel->access_token;

            // Search for customer by phone
            $customerResponse = Http::withToken($accessToken)
                ->get("https://{$shop}/admin/api/2024-01/customers/search.json?query={$phone}");

            if (!$customerResponse->successful() || empty($customerResponse->json()['customers'])) {
                return response()->json(['error' => 'No customer found with this phone'], 404);
            }

            $customer = $customerResponse->json()['customers'][0];
            $customerId = $customer['id'];

            // Get customer orders
            $ordersResponse = Http::withToken($accessToken)
                ->get("https://{$shop}/admin/api/2024-01/orders.json?customer_id={$customerId}&status=any&limit=1&sort_by=created_at&sort_order=desc");

            if (!$ordersResponse->successful() || empty($ordersResponse->json()['orders'])) {
                return response()->json(['error' => 'No orders found for this customer'], 404);
            }

            $order = $ordersResponse->json()['orders'][0];

            // Format order data for AI (same pattern as Salla)
            $formattedOrder = $this->formatOrderForAI($order);

            return response()->json([
                'success' => true,
                'order' => $formattedOrder,
                'raw_order' => $order,
            ]);

        } catch (\Exception $e) {
            Log::error('Shopify order lookup error', [
                'error' => $e->getMessage(),
                'phone' => $phone,
            ]);
            return response()->json(['error' => 'Failed to fetch orders'], 500);
        }
    }

    private function formatOrderForAI(array $order): string
    {
        $orderNumber = $order['order_number'] ?? $order['id'] ?? 'N/A';
        $status = $order['financial_status'] ?? 'Unknown';
        $total = $order['total_price'] ?? '0';
        $currency = $order['currency'] ?? 'USD';
        $processedAt = $order['processed_at'] ?? 'Not specified';

        $products = [];
        foreach ($order['line_items'] ?? [] as $item) {
            $products[] = ($item['title'] ?? 'Unknown') . ' x' . ($item['quantity'] ?? 1);
        }

        return "Order #{$orderNumber}\nStatus: {$status}\nTotal: {$total} {$currency}\n" .
               "Products: " . implode(', ', $products) . "\n" .
               "Processed At: {$processedAt}";
    }

    private function registerWebhook(string $shop, string $accessToken)
    {
        try {
            $webhookUrl = env('APP_URL') . '/api/shopify/webhook';
            $topic = 'orders/create';

            $response = Http::withToken($accessToken)
                ->post("https://{$shop}/admin/api/2024-01/webhooks.json", [
                    'webhook' => [
                        'topic' => $topic,
                        'address' => $webhookUrl,
                        'format' => 'json',
                    ],
                ]);

            if ($response->successful()) {
                Log::info('Shopify webhook registered', [
                    'shop' => $shop,
                    'webhook_url' => $webhookUrl,
                    'topic' => $topic,
                ]);
            } else {
                Log::error('Failed to register Shopify webhook', [
                    'shop' => $shop,
                    'response' => $response->json(),
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Shopify webhook registration error', [
                'shop' => $shop,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function webhook(Request $request)
    {
        try {
            $topic = $request->header('X-Shopify-Topic');
            $shop = $request->header('X-Shopify-Shop-Domain');
            $payload = $request->all();

            Log::info('Shopify webhook received', [
                'topic' => $topic,
                'shop' => $shop,
            ]);

            // Handle order creation
            if ($topic === 'orders/create') {
                $order = $payload;
                $customerPhone = $order['customer']['phone'] ?? '';

                if (!empty($customerPhone)) {
                    // Find channel by shop domain
                    $channel = Channel::where('type', 'shopify')
                        ->where('page_id', $shop)
                        ->where('status', 'connected')
                        ->first();

                    if ($channel) {
                        // Check if conversation exists for this customer
                        $conversation = Conversation::where('channel_id', $channel->id)
                            ->where('sender_id', $customerPhone)
                            ->first();

                        if ($conversation) {
                            // Send order info as system message
                            $formattedOrder = $this->formatOrderForAI($order);
                            Message::create([
                                'conversation_id' => $conversation->id,
                                'content' => "New order received:\n\n{$formattedOrder}",
                                'direction' => 'inbound',
                                'is_ai' => false,
                                'status' => 'received',
                            ]);
                        }
                    }
                }
            }

            return response('OK', 200);

        } catch (\Exception $e) {
            Log::error('Shopify webhook error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response('OK', 200);
        }
    }
}