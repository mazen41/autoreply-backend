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
        // Salla REST API base — all merchant/store endpoints live here
        $this->apiBaseUrl = 'https://api.salla.dev/admin/v2';
        $this->clientId = env('SALLA_CLIENT_ID', '');
        $this->clientSecret = env('SALLA_CLIENT_SECRET', '');
        $this->redirectUri = env('SALLA_REDIRECT_URI', env('APP_URL') . '/api/channels/callback/salla');

        if (empty($this->clientId) || empty($this->clientSecret)) {
            Log::error('Salla credentials not configured', [
                'client_id_set'     => !empty($this->clientId),
                'client_secret_set' => !empty($this->clientSecret),
            ]);
        }
    }

    /**
     * Generate Salla OAuth authorization URL.
     * offline_access is mandatory to receive a refresh_token in Custom Mode.
     */
    public function getAuthorizationUrl(string $state): string
    {
        if (!$this->isConfigured()) {
            throw new \Exception('Salla credentials are not configured');
        }

        $params = [
            'client_id'     => $this->clientId,
            'redirect_uri'  => $this->redirectUri,
            'response_type' => 'code',
            'scope'         => 'offline_access settings.read orders.read products.read customers.read',
            'state'         => $state,
        ];

        return 'https://accounts.salla.sa/oauth2/auth?' . http_build_query($params);
    }

    /**
     * Exchange authorization code for access token.
     */
    public function exchangeCodeForToken(string $code): array
    {
        $response = Http::asForm()->post('https://accounts.salla.sa/oauth2/token', [
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'redirect_uri'  => $this->redirectUri,
            'code'          => $code,
            'grant_type'    => 'authorization_code',
        ]);

        if (!$response->successful()) {
            Log::error('Salla token exchange failed', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            throw new \Exception('Failed to exchange authorization code for token');
        }

        $tokenData = $response->json();

        Log::info('[SALLA OAuth Token Response]', [
            'status'         => $response->status(),
            'body'           => $tokenData,
            'granted_scopes' => $tokenData['scope'] ?? 'none',
        ]);

        return $tokenData;
    }

    /**
     * Get authorized user / merchant info from Salla OAuth endpoint.
     *
     * Correct endpoint: GET https://accounts.salla.sa/oauth2/user/info
     * Returns: { id, name, email, mobile, merchant: { id, ... } }
     */
    public function getUserInfo(string $accessToken): array
    {
        $response = Http::withToken($accessToken)
            ->accept('application/json')
            ->get('https://accounts.salla.sa/oauth2/user/info');

        if (!$response->successful()) {
            Log::error('Salla getUserInfo failed', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            throw new \Exception('Failed to get Salla user info: ' . $response->status());
        }

        $data = $response->json();

        // Response shape: { "status": 200, "data": { "id": ..., "merchant": { ... } } }
        return $data['data'] ?? $data;
    }

    /**
     * Refresh access token using refresh token.
     */
    public function refreshAccessToken(string $refreshToken): array
    {
        $response = Http::asForm()->post('https://accounts.salla.sa/oauth2/token', [
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'refresh_token' => $refreshToken,
            'grant_type'    => 'refresh_token',
        ]);

        if (!$response->successful()) {
            Log::error('Salla token refresh failed', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            throw new \Exception('Failed to refresh access token');
        }

        return $response->json();
    }

    /**
     * Make an authenticated REST call to the Salla Admin API v2.
     * Token-agnostic version (raw access token string, no refresh logic).
     */
    protected function apiCall(string $method, string $endpoint, array $data = [], string $accessToken = ''): array
    {
        $url     = $this->apiBaseUrl . $endpoint;
        $request = Http::withToken($accessToken)->accept('application/json');

        $response = strtoupper($method) === 'GET'
            ? $request->get($url, $data)
            : $request->{strtolower($method)}($url, $data);

        if (!$response->successful()) {
            Log::error("Salla API call failed: {$method} {$url}", [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);

            if ($response->status() === 429) {
                $retryAfter = $response->header('Retry-After', 60);
                throw new \Exception("Rate limited. Retry after {$retryAfter} seconds");
            }

            if ($response->status() === 401) {
                throw new \Exception('Access token expired');
            }

            throw new \Exception("API call failed: {$response->status()} — " . $response->body());
        }

        return $response->json();
    }

    /**
     * Bug 4 fix: Channel-aware API call that automatically refreshes token on 401.
     * On successful refresh, updates the Channel model with new tokens.
     * If refresh also fails, marks channel status = 'token_expired' so the dashboard shows it.
     */
    protected function apiCallForChannel(Channel $channel, string $method, string $endpoint, array $data = []): array
    {
        $accessToken = $channel->access_token;
        $url         = $this->apiBaseUrl . $endpoint;

        try {
            return $this->apiCall($method, $endpoint, $data, $accessToken);
        } catch (\Exception $e) {
            // If token expired, try to refresh once
            if (str_contains($e->getMessage(), 'Access token expired') || str_contains($e->getMessage(), '401')) {
                $refreshToken = $channel->refresh_token;

                if (!$refreshToken) {
                    Log::error('Salla: token expired but no refresh_token stored — marking channel as token_expired', [
                        'channel_id' => $channel->id,
                    ]);
                    $channel->update(['status' => 'token_expired']);
                    throw new \Exception("Salla token expired and no refresh token available for channel {$channel->id}");
                }

                try {
                    Log::info('Salla: attempting token refresh', ['channel_id' => $channel->id]);
                    $newTokens = $this->refreshAccessToken($refreshToken);

                    // Update the channel with new tokens
                    $channel->access_token  = $newTokens['access_token'];
                    $channel->refresh_token = $newTokens['refresh_token'] ?? $refreshToken;
                    if (!empty($newTokens['expires_in'])) {
                        $channel->token_expires_at = now()->addSeconds($newTokens['expires_in']);
                    }
                    $channel->save();

                    Log::info('Salla: token refreshed successfully', ['channel_id' => $channel->id]);

                    // Retry the original request once with the new token
                    return $this->apiCall($method, $endpoint, $data, $channel->access_token);

                } catch (\Exception $refreshEx) {
                    Log::error('Salla: token refresh failed — marking channel as token_expired', [
                        'channel_id' => $channel->id,
                        'error'      => $refreshEx->getMessage(),
                    ]);
                    $channel->update(['status' => 'token_expired']);
                    throw new \Exception("Salla token refresh failed for channel {$channel->id}: " . $refreshEx->getMessage());
                }
            }

            throw $e;
        }
    }

    /**
     * Get store / merchant information.
     *
     * Correct Salla Custom-Mode endpoint:
     *   GET https://api.salla.dev/admin/v2/store/info
     *
     * Response shape:
     *   { "status": 200, "success": true, "data": { "id": ..., "name": ..., ... } }
     */
    public function getStoreInfo(string $accessToken): array
    {
        $response = $this->apiCall('GET', '/store/info', [], $accessToken);

        // Unwrap the standard Salla envelope
        if (isset($response['data']) && is_array($response['data'])) {
            Log::info('Salla store info fetched successfully', [
                'store_id'   => $response['data']['id']   ?? null,
                'store_name' => $response['data']['name'] ?? null,
            ]);
            return $response['data'];
        }

        // Fallback: response itself is the store object
        if (isset($response['id'])) {
            return $response;
        }

        Log::error('Salla getStoreInfo: unexpected response shape', ['response' => $response]);
        throw new \Exception('Unexpected response shape from Salla store/info endpoint');
    }

    public function getCustomers(string $accessToken, array $params = []): array
    {
        return $this->apiCall('GET', '/customers', $params, $accessToken);
    }

    public function getCustomerByPhone(string $accessToken, string $phone): ?array
    {
        $result = $this->getCustomers($accessToken, ['mobile' => $phone]);
        return $result['data'][0] ?? null;
    }

    /**
     * Get orders list, optionally filtered.
     *
     * Correct endpoint: GET /orders  (NOT /customers/{id}/orders — that does not exist)
     * Filter by customer_id via query param.
     */
    public function getOrders(string $accessToken, array $params = []): array
    {
        return $this->apiCall('GET', '/orders', $params, $accessToken);
    }

    /**
     * Get a single order by its ID.
     * Correct endpoint: GET /orders/{id}
     */
    public function getOrder(string $accessToken, string $orderId): array
    {
        return $this->apiCall('GET', "/orders/{$orderId}", [], $accessToken);
    }

    public function getProducts(string $accessToken, array $params = []): array
    {
        return $this->apiCall('GET', '/products', $params, $accessToken);
    }

    public function getProduct(string $accessToken, string $productId): array
    {
        return $this->apiCall('GET', "/products/{$productId}", [], $accessToken);
    }

    /**
     * Get orders for a specific customer.
     *
     * Salla has NO /customers/{id}/orders route.
     * The correct approach is GET /orders?customer_id={id}
     */
    public function getCustomerOrders(string $accessToken, string $customerId, array $params = []): array
    {
        return $this->apiCall('GET', '/orders', array_merge(['customer_id' => $customerId], $params), $accessToken);
    }

    /**
     * Get latest order by phone number.
     *
     * Flow:
     *  1. Search customers by mobile → verify the returned customer's phone matches
     *  2. GET /orders?customer_id={id}&per_page=1
     *
     * IMPORTANT: Salla's /customers?mobile= filter can return fuzzy/partial matches.
     * We always verify the returned customer's phone before trusting the order,
     * so we never return an order belonging to a different customer.
     */
    public function getLatestOrderByPhone(string $accessToken, string $phone): ?array
    {
        $cleanPhone = preg_replace('/[^0-9]/', '', $phone);

        // Build all phone variants we'll accept as a match
        $last9     = substr($cleanPhone, -9);
        $local     = '0' . $last9;               // 0XXXXXXXXX
        $saudi966  = '966' . $last9;              // 966XXXXXXXXX
        $intl      = '+966' . $last9;             // +966XXXXXXXXX

        $acceptedVariants = array_unique([$cleanPhone, $local, $saudi966, $intl, $last9]);

        // Bug 5 fix: known Salla sandbox/placeholder phones — ignore them
        $placeholderPhones = ['555555555', '0555555555', '966555555555'];

        $customer = null;

        // Try each variant until we find a customer whose stored phone actually matches
        foreach ([$cleanPhone, $local] as $searchPhone) {
            $result    = $this->getCustomers($accessToken, ['mobile' => $searchPhone]);
            $candidates = $result['data'] ?? [];

            foreach ($candidates as $candidate) {
                $storedRaw   = preg_replace('/[^0-9]/', '', $candidate['mobile'] ?? '');
                $storedLast9 = substr($storedRaw, -9);

                // Bug 5 fix: skip known placeholder/test records
                if (in_array($storedRaw, $placeholderPhones) || in_array($storedLast9, ['555555555'])) {
                    Log::info('Salla: skipping placeholder/test phone customer', [
                        'returned_phone' => $candidate['mobile'] ?? 'N/A',
                        'customer_id'    => $candidate['id'] ?? 'N/A',
                    ]);
                    continue;
                }

                if ($storedLast9 === $last9) {
                    $customer = $candidate;
                    Log::info('Salla: customer phone verified', [
                        'searched'    => $searchPhone,
                        'stored'      => $candidate['mobile'] ?? 'N/A',
                        'customer_id' => $candidate['id'],
                    ]);
                    break 2; // found a verified match — stop searching
                }

                // Bug 5 fix: log the full raw record so mismatches are debuggable
                Log::warning('Salla: customer phone mismatch — skipping', [
                    'searched'        => $searchPhone,
                    'returned_phone'  => $candidate['mobile'] ?? 'N/A',
                    'returned_last9'  => $storedLast9,
                    'expected_last9'  => $last9,
                    'customer_id'     => $candidate['id'] ?? 'N/A',
                    'customer_record' => json_encode(array_intersect_key($candidate, array_flip(['id', 'mobile', 'first_name', 'last_name', 'email']))),
                ]);
            }
        }

        if (!$customer) {
            Log::info('Salla: no verified customer found for phone', [
                'phone'    => $cleanPhone,
                'variants' => $acceptedVariants,
            ]);
            return null;
        }

        $orders = $this->getCustomerOrders($accessToken, (string) $customer['id'], [
            'per_page' => 1,
        ]);

        $order = $orders['data'][0] ?? null;

        if ($order) {
            Log::info('Salla: latest order found for verified customer', [
                'customer_id'    => $customer['id'],
                'customer_phone' => $customer['mobile'] ?? 'N/A',
                'order_id'       => $order['id'] ?? null,
            ]);
        } else {
            Log::info('Salla: customer found but has no orders', [
                'customer_id' => $customer['id'],
            ]);
        }

        return $order;
    }

    /**
     * Bug 4+6: Channel-aware version of getLatestOrderByPhone — uses auto-refresh.
     */
    public function getLatestOrderByPhoneForChannel(Channel $channel, string $phone): ?array
    {
        // We need to use the channel's token for the customers API call.
        // Temporarily override apiCall to use channel-aware version.
        // Strategy: make direct HTTP calls using the channel's refreshable token.
        $cleanPhone = preg_replace('/[^0-9]/', '', $phone);
        $last9      = substr($cleanPhone, -9);
        $local      = '0' . $last9;

        $placeholderPhones = ['555555555', '0555555555', '966555555555'];
        $customer = null;

        foreach ([$cleanPhone, $local] as $searchPhone) {
            $result    = $this->apiCallForChannel($channel, 'GET', '/customers', ['mobile' => $searchPhone]);
            $candidates = $result['data'] ?? [];

            foreach ($candidates as $candidate) {
                $storedRaw   = preg_replace('/[^0-9]/', '', $candidate['mobile'] ?? '');
                $storedLast9 = substr($storedRaw, -9);

                if (in_array($storedRaw, $placeholderPhones) || in_array($storedLast9, ['555555555'])) {
                    continue;
                }

                if ($storedLast9 === $last9) {
                    $customer = $candidate;
                    break 2;
                }

                Log::warning('Salla: customer phone mismatch (channel-aware) — skipping', [
                    'searched'        => $searchPhone,
                    'returned_phone'  => $candidate['mobile'] ?? 'N/A',
                    'returned_last9'  => $storedLast9,
                    'expected_last9'  => $last9,
                    'customer_record' => json_encode(array_intersect_key($candidate, array_flip(['id', 'mobile', 'first_name', 'last_name']))),
                ]);
            }
        }

        if (!$customer) {
            return null;
        }

        $orders = $this->apiCallForChannel($channel, 'GET', '/orders', [
            'customer_id' => $customer['id'],
            'per_page'    => 1,
        ]);

        return $orders['data'][0] ?? null;
    }

    /**
     * Bug 6: Get order by reference number using channel-aware (auto-refresh) token.
     */
    public function getOrderForChannel(Channel $channel, string $orderId): array
    {
        return $this->apiCallForChannel($channel, 'GET', "/orders/{$orderId}");
    }

    public function formatOrderForAI(array $order): string
    {
        $orderNumber      = $order['reference_id'] ?? $order['id'] ?? 'N/A';
        $status           = $order['status']['name'] ?? $order['status'] ?? 'Unknown';
        $total            = $order['total']['amount'] ?? '0';
        $currency         = $order['total']['currency'] ?? 'SAR';
        $shippingStatus   = $order['shipping']['status']['name'] ?? 'Not shipped';
        $expectedDelivery = $order['shipping']['estimated_delivery'] ?? 'Not specified';

        $products = [];
        foreach ($order['items'] ?? [] as $item) {
            $products[] = ($item['product']['name'] ?? 'Unknown') . ' x' . ($item['quantity'] ?? 1);
        }

        return "Order #{$orderNumber}\nStatus: {$status}\nTotal: {$total} {$currency}\n" .
               "Products: " . implode(', ', $products) . "\n" .
               "Shipping Status: {$shippingStatus}\nExpected Delivery: {$expectedDelivery}";
    }

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
        return hash_equals(hash_hmac('sha256', $payload, $webhookSecret), $signature);
    }
}
