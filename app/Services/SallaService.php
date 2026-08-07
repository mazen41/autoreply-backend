<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\Channel;

class SallaService
{
    protected string $apiBaseUrl;
    protected string $clientId;
    protected string $clientSecret;
    protected string $redirectUri;

    protected function isConfigured(): bool
    {
        return !empty($this->clientId) && !empty($this->clientSecret);
    }

    public function __construct()
    {
        $this->apiBaseUrl = env('SALLA_API_BASE_URL', 'https://api.salla.dev');
        $this->clientId = env('SALLA_CLIENT_ID', '');
        $this->clientSecret = env('SALLA_CLIENT_SECRET', '');
        $this->redirectUri = env('SALLA_REDIRECT_URI', env('APP_URL') . '/api/salla/callback');
        
        if (empty($this->clientId) || empty($this->clientSecret)) {
            Log::error('Salla credentials not configured', [
                'client_id_set' => !empty($this->clientId),
                'client_secret_set' => !empty($this->clientSecret),
            ]);
        }
    }

    /**
     * Generate Salla OAuth authorization URL
     */
    public function getAuthorizationUrl(string $state): string
    {
        if (!$this->isConfigured()) {
            throw new \Exception('Salla credentials are not configured');
        }

        $params = [
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'response_type' => 'code',
            'scope' => 'orders.read offline_access products.read customers.read',
            'state' => $state,
        ];

        return 'https://accounts.salla.sa/oauth2/authorize?' . http_build_query($params);
    }

    /**
     * Exchange authorization code for access token
     */
    public function exchangeCodeForToken(string $code): array
    {
        $response = Http::asForm()->post('https://accounts.salla.sa/oauth2/token', [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'redirect_uri' => $this->redirectUri,
            'code' => $code,
            'grant_type' => 'authorization_code',
        ]);

        if (!$response->successful()) {
            Log::error('Salla token exchange failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new \Exception('Failed to exchange authorization code for token');
        }

        return $response->json();
    }

    /**
     * Refresh access token using refresh token
     */
    public function refreshAccessToken(string $refreshToken): array
    {
        $response = Http::asForm()->post('https://accounts.salla.sa/oauth2/token', [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token',
        ]);

        if (!$response->successful()) {
            Log::error('Salla token refresh failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new \Exception('Failed to refresh access token');
        }

        return $response->json();
    }

    /**
     * Make authenticated API call to Salla
     */
    protected function apiCall(string $method, string $endpoint, array $data = [], string $accessToken = null): array
    {
        $url = $this->apiBaseUrl . $endpoint;
        
        $response = Http::withToken($accessToken)
            ->accept('application/json')
            ->{$method}($url, $data);

        if (!$response->successful()) {
            Log::error("Salla API call failed: {$method} {$endpoint}", [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            
            // Handle rate limiting
            if ($response->status() === 429) {
                $retryAfter = $response->header('Retry-After', 60);
                throw new \Exception("Rate limited. Retry after {$retryAfter} seconds");
            }
            
            // Handle token expiration
            if ($response->status() === 401) {
                throw new \Exception('Access token expired');
            }

            throw new \Exception("API call failed: {$response->status()}");
        }

        return $response->json();
    }

    /**
     * Get store information
     */
    public function getStoreInfo(string $accessToken): array
    {
        $response = $this->apiCall('GET', '/store', [], $accessToken);
        // Salla wraps responses: { "status": 200, "success": true, "data": { "id": ..., "name": ... } }
        // Unwrap so callers get the store object directly.
        if (isset($response['data']) && is_array($response['data'])) {
            return $response['data'];
        }
        return $response;
    }

    /**
     * Get customers list
     */
    public function getCustomers(string $accessToken, array $params = []): array
    {
        return $this->apiCall('GET', '/customers', $params, $accessToken);
    }

    /**
     * Get customer by phone number
     */
    public function getCustomerByPhone(string $accessToken, string $phone): ?array
    {
        $customers = $this->getCustomers($accessToken, ['mobile' => $phone]);
        return $customers['data'] ?? null;
    }

    /**
     * Get customer orders
     */
    public function getCustomerOrders(string $accessToken, string $customerId, array $params = []): array
    {
        return $this->apiCall('GET', "/customers/{$customerId}/orders", $params, $accessToken);
    }

    /**
     * Get order details
     */
    public function getOrder(string $accessToken, string $orderId): array
    {
        return $this->apiCall('GET', "/orders/{$orderId}", [], $accessToken);
    }

    /**
     * Get products list
     */
    public function getProducts(string $accessToken, array $params = []): array
    {
        return $this->apiCall('GET', '/products', $params, $accessToken);
    }

    /**
     * Get product details
     */
    public function getProduct(string $accessToken, string $productId): array
    {
        return $this->apiCall('GET', "/products/{$productId}", [], $accessToken);
    }

    /**
     * Get order by customer phone number (latest order)
     */
    public function getLatestOrderByPhone(string $accessToken, string $phone): ?array
    {
        $customer = $this->getCustomerByPhone($accessToken, $phone);
        if (!$customer) {
            return null;
        }

        $orders = $this->getCustomerOrders($accessToken, $customer['id'], [
            'sort' => 'created_at',
            'page' => 1,
            'per_page' => 1,
        ]);

        return $orders['data'][0] ?? null;
    }

    /**
     * Format order data for AI context
     */
    public function formatOrderForAI(array $order): string
    {
        $orderNumber = $order['reference_id'] ?? $order['id'] ?? 'N/A';
        $status = $order['status']['name'] ?? $order['status'] ?? 'Unknown';
        $total = $order['total']['amount'] ?? '0';
        $currency = $order['total']['currency'] ?? 'SAR';
        
        $products = [];
        foreach ($order['items'] ?? [] as $item) {
            $products[] = ($item['product']['name'] ?? 'Unknown') . 
                         ' x' . ($item['quantity'] ?? 1);
        }
        
        $shippingStatus = $order['shipping']['status']['name'] ?? 'Not shipped';
        $expectedDelivery = $order['shipping']['estimated_delivery'] ?? 'Not specified';

        return "Order #{$orderNumber}\n" .
               "Status: {$status}\n" .
               "Total: {$total} {$currency}\n" .
               "Products: " . implode(', ', $products) . "\n" .
               "Shipping Status: {$shippingStatus}\n" .
               "Expected Delivery: {$expectedDelivery}";
    }

    /**
     * Verify webhook signature
     */
    public function verifyWebhookSignature(string $payload, ?string $signature): bool
    {
        if (empty($signature)) {
            Log::warning('Webhook signature is empty');
            return false;
        }

        $webhookSecret = env('SALLA_WEBHOOK_SECRET');
        if (empty($webhookSecret)) {
            Log::error('SALLA_WEBHOOK_SECRET not configured');
            return false;
        }

        $expectedSignature = hash_hmac('sha256', $payload, $webhookSecret);
        return hash_equals($expectedSignature, $signature);
    }
}
