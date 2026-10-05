<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
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
        $this->clientId = config('services.salla.client_id', env('SALLA_CLIENT_ID', ''));
        $this->clientSecret = config('services.salla.client_secret', env('SALLA_CLIENT_SECRET', ''));
        $this->redirectUri = config('services.salla.redirect_uri', env('SALLA_REDIRECT_URI', env('APP_URL') . '/api/channels/callback/salla'));

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

        $clientId    = config('services.salla.client_id', $this->clientId);
        $redirectUri = config('services.salla.redirect_uri', $this->redirectUri);

        $params = [
            'client_id'     => $clientId,
            'redirect_uri'  => $redirectUri,
            'response_type' => 'code',
            'scope'         => 'offline_access settings.read orders.read_write customers.read_write products.read metadata.read',
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
            'body'           => '[redacted]',
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
                $responseBody = $response->body();

                if ($this->isMissingScopeError($responseBody)) {
                    throw new \Exception('Salla API scope missing: ' . $responseBody);
                }

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
            if ($this->isMissingScopeError($e->getMessage())) {
                Log::error('Salla API request denied because the connected store is missing a required scope; reconnect required', [
                    'channel_id' => $channel->id,
                    'endpoint' => $endpoint,
                    'error' => $e->getMessage(),
                ]);

                // Refreshing a token cannot add scopes that were not granted during OAuth.
                // Keep the channel connected so the dashboard does not misreport this as expiry.
                throw $e;
            }

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
                    if ($this->isMissingScopeError($refreshEx->getMessage())) {
                        Log::error('Salla API request still denied after token refresh because the store is missing a required scope; reconnect required', [
                            'channel_id' => $channel->id,
                            'endpoint' => $endpoint,
                            'error' => $refreshEx->getMessage(),
                        ]);

                        // The token refresh succeeded, but its grant still lacks the needed scope.
                        // Preserve the refreshed credentials and connected status.
                        throw $refreshEx;
                    }

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
     * Salla uses 401 for both expired tokens and insufficient OAuth scopes.
     * Missing scopes require reconnecting the store, not marking its token expired.
     */
    protected function isMissingScopeError(string $message): bool
    {
        return str_contains(strtolower($message), 'should have access to one of those scopes');
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

    /**
     * Resolve existing Salla customer_id or create a new customer via POST /customers.
     */
    public function resolveOrCreateCustomerForChannel(Channel $channel, string $phone, ?string $fullName = null, ?string $email = null): ?int
    {
        $cleanPhone = preg_replace('/[^0-9]/', '', $phone);
        if (empty($cleanPhone)) {
            return null;
        }

        // 1. Check if customer already exists in Salla by searching mobile
        $last9 = substr($cleanPhone, -9);
        $local = '0' . $last9;
        foreach ([$cleanPhone, $local] as $searchPhone) {
            try {
                $res = $this->apiCallForChannel($channel, 'GET', '/customers', ['mobile' => $searchPhone]);
                $candidates = $res['data'] ?? [];
                foreach ($candidates as $cand) {
                    $candRaw = preg_replace('/[^0-9]/', '', $cand['mobile'] ?? '');
                    if (substr($candRaw, -9) === $last9 && !empty($cand['id'])) {
                        Log::info('SallaService: found existing Salla customer_id', ['customer_id' => $cand['id']]);
                        return (int)$cand['id'];
                    }
                }
            } catch (\Exception $e) {
                Log::warning('SallaService: GET /customers lookup failed', ['error' => $e->getMessage()]);
            }
        }

        // 2. Create customer via POST /customers if not found
        try {
            // Split full name into first_name and last_name deterministically
            $nameParts = $this->splitFullName($fullName ?? '');
            $firstName = $nameParts['first'];
            $lastName  = $nameParts['last'] ?: 'Customer';

            // Salla expects the calling code in mobile_code_country and the
            // national number (without the calling code) in mobile.
            $phoneData = $this->normalizePhoneForCustomer($phone);

            $payload = [
                'first_name'          => $firstName,
                'last_name'           => $lastName,
                'mobile'              => $phoneData['mobile'],
                'mobile_code_country' => $phoneData['mobile_code_country'],
            ];
            if ($email) {
                $payload['email'] = $email;
            }

            $res = $this->apiCallForChannel($channel, 'POST', '/customers', $payload);
            $customerId = $res['data']['id'] ?? $res['id'] ?? null;
            if ($customerId) {
                Log::info('SallaService: created new Salla customer', ['customer_id' => $customerId]);
                return (int)$customerId;
            }
        } catch (\Exception $e) {
            Log::warning('SallaService: POST /customers failed', ['error' => $e->getMessage()]);
        }

        return null;
    }

    /** Fetch a Salla API response while treating cache failures as non-fatal. */
    protected function cachedSallaResponse(string $cacheKey, string $endpoint, callable $fetch): array
    {
        try {
            $cached = \Illuminate\Support\Facades\Cache::get($cacheKey);
            if (is_array($cached)) {
                return $cached;
            }
        } catch (\Throwable $e) {
            Log::warning('SallaService: cache read failed; fetching API response without cache', [
                'cache_key' => $cacheKey,
                'error' => $e->getMessage(),
            ]);
        }

        // The API result is required for checkout. A local cache write failure
        // (for example, a missing file-cache shard directory) must not discard it.
        $response = $fetch();

        try {
            \Illuminate\Support\Facades\Cache::put($cacheKey, $response, 43200);
        } catch (\Throwable $e) {
            Log::warning('SallaService: cache write failed; continuing with live API response', [
                'cache_key' => $cacheKey,
                'endpoint' => $endpoint,
                'error' => $e->getMessage(),
            ]);
        }

        return $response;
    }

    /**
     * Download one country's Salla city list into the local catalog.
     * Salla permits at most 60 cities per page; checkout reads this catalog
     * locally so it never performs a multi-page network scan while placing an order.
     */
    public function syncCityCatalog(Channel $channel, string $countryCode, ?callable $onProgress = null): int
    {
        $countryCode = strtoupper(trim($countryCode));
        if ($onProgress) {
            $onProgress(['stage' => 'countries_request', 'country_code' => $countryCode]);
        }
        $countriesResponse = $this->apiCallForChannel($channel, 'GET', '/countries');
        $country = collect($countriesResponse['data'] ?? [])->first(
            fn ($candidate) => strtoupper((string) ($candidate['code'] ?? '')) === $countryCode
        );
        $countryId = $country['id'] ?? null;

        if (!$countryId) {
            throw new \RuntimeException("Salla country {$countryCode} was not found in the country catalog.");
        }

        $endpoint = "/countries/{$countryId}/cities";
        $totalSynced = 0;
        $now = now();

        for ($page = 1; $page <= 500; $page++) {
            if ($onProgress) {
                $onProgress([
                    'stage' => 'page_request',
                    'country_code' => $countryCode,
                    'page' => $page,
                ]);
            }

            $response = $this->apiCallForChannel($channel, 'GET', $endpoint, [
                'page' => $page,
                'per_page' => 60,
                'count' => 60,
            ]);
            $cities = $response['data'] ?? [];

            if ($page === 1 && empty($cities)) {
                throw new \RuntimeException("Salla returned no cities for {$countryCode}; local catalog was not updated.");
            }

            if ($cities) {
                $rows = [];
                foreach ($cities as $city) {
                    if (empty($city['id'])) {
                        continue;
                    }

                    $rows[] = [
                        'channel_id' => $channel->id,
                        'country_code' => $countryCode,
                        'country_id' => (int) $countryId,
                        'salla_city_id' => (int) $city['id'],
                        'name' => (string) ($city['name'] ?? $city['name_en'] ?? $city['name_ar'] ?? ''),
                        'name_en' => isset($city['name_en']) ? (string) $city['name_en'] : null,
                        'name_ar' => isset($city['name_ar']) ? (string) $city['name_ar'] : null,
                        'synced_at' => $now,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                if ($rows) {
                    DB::table('salla_cities')->upsert(
                        $rows,
                        ['channel_id', 'country_code', 'salla_city_id'],
                        ['country_id', 'name', 'name_en', 'name_ar', 'synced_at', 'updated_at']
                    );
                    $totalSynced += count($rows);
                }
            }

            $pagination = $response['pagination'] ?? [];
            $totalPages = (int) ($pagination['totalPages'] ?? $pagination['total_pages'] ?? 1);
            if ($onProgress) {
                $onProgress([
                    'stage' => 'page_complete',
                    'country_code' => $countryCode,
                    'page' => $page,
                    'total_pages' => $totalPages,
                    'response_per_page' => (int) ($pagination['perPage'] ?? $pagination['per_page'] ?? 0),
                    'page_count' => count($cities),
                    'total_synced' => $totalSynced,
                ]);
            }

            $hasNextPage = $page < $totalPages || !empty($pagination['links']['next']);
            if (!$hasNextPage) {
                break;
            }

            if ($page === 500) {
                throw new \RuntimeException('Salla city pagination exceeded the 500-page safety limit.');
            }
        }

        DB::table('salla_cities')
            ->where('channel_id', $channel->id)
            ->where('country_code', $countryCode)
            ->where('synced_at', '<', $now)
            ->delete();

        return $totalSynced;
    }

    protected function normalizeCitySearchText(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = preg_replace('/[^\\p{L}\\p{N}\\s]/u', ' ', $value) ?? $value;
        $value = preg_replace('/\\s+/u', ' ', $value) ?? $value;
        $value = trim($value);

        // Salla names may include the common transliterated prefixes "Al"/"El"
        // while customers usually write the shorter city name (e.g. "Giza").
        return preg_replace('/^(?:al|el)\\s+/u', '', $value) ?? $value;
    }

    /**
     * Dynamically resolve Salla shipping address fields against the merchant's Salla account data.
     * Returns null if city_id or required address components cannot be confidently resolved.
     */
    public function resolveShippingAddressForChannel(Channel $channel, string $freeformAddress, string $phone = ''): ?array
    {
        if (empty(trim($freeformAddress))) {
            return null;
        }

        // Match the phone's country to the locally synced Salla catalog, keeping
        // network pagination out of the checkout/order-creation path.
        $phoneData = $this->normalizePhoneForCustomer($phone);
        $countryCode = match ($phoneData['mobile_code_country']) {
            '+20' => 'EG', '+966' => 'SA', '+971' => 'AE', '+965' => 'KW',
            '+974' => 'QA', '+973' => 'BH', '+968' => 'OM', '+967' => 'YE',
            '+962' => 'JO', '+961' => 'LB', '+964' => 'IQ', '+1' => 'US',
            '+44' => 'GB', '+90' => 'TR', '+91' => 'IN', '+33' => 'FR',
            '+49' => 'DE', default => 'SA',
        };

        $cities = DB::table('salla_cities')
            ->where('channel_id', $channel->id)
            ->where('country_code', $countryCode)
            ->get();
        $countryId = $cities->first()->country_id ?? null;
        $matchedCityId = null;
        $matchedCityNameLength = 0;
        $availableCityNames = [];

        $normalizedAddress = $this->normalizeCitySearchText($freeformAddress);
        foreach ($cities as $city) {
            foreach ([$city->name, $city->name_en, $city->name_ar] as $cityName) {
                $cityName = trim((string) $cityName);
                if ($cityName === '') {
                    continue;
                }

                $availableCityNames[] = $cityName;
                $normalizedCityName = $this->normalizeCitySearchText($cityName);
                $cityNameLength = mb_strlen($normalizedCityName);
                if (
                    $cityNameLength > $matchedCityNameLength
                    && str_contains($normalizedAddress, $normalizedCityName)
                ) {
                    $matchedCityId = (int) $city->salla_city_id;
                    $matchedCityNameLength = $cityNameLength;
                }
            }
        }

        // STRICT RULE 1: If city cannot be matched against merchant's Salla city list, DO NOT fake city_id = 1! Return null.
        if (!$matchedCityId) {
            Log::info('SallaService: could not match city in address against merchant Salla cities', [
                'channel_id' => $channel->id,
                'country_id' => (int) $countryId,
                'country_code' => $countryCode,
                'address'    => $freeformAddress,
                'catalog_synced' => $cities->isNotEmpty(),
                'cities_checked' => count($availableCityNames),
                'available_city_names_sample' => array_slice($availableCityNames, 0, 30),
            ]);
            return null;
        }

        // 2. Parse street number (extracted if present)
        $streetNumber = null;
        if (preg_match('/\b(\d{1,5})\b/', $freeformAddress, $sm)) {
            $streetNumber = $sm[1];
        }

        // 3. Parse block/district
        $block = null;
        if (preg_match('/(?:district|block|neighborhood|حي|منطقة|شارع|فصل|فيصل|طريق)\s*[:\-]?\s*([A-Za-z\x{0600}-\x{06FF}\s]{2,30})/ui', $freeformAddress, $bm)) {
            $block = trim($bm[1]);
        } else {
            $words = array_filter(explode(' ', trim($freeformAddress)));
            if (count($words) >= 1) {
                $block = implode(' ', array_slice($words, 0, 3));
            }
        }

        // 4. Parse postal code (if 5-digit zip exists)
        $postalCode = null;
        if (preg_match('/\b(\d{5})\b/', $freeformAddress, $pm)) {
            $postalCode = $pm[1];
        }

        $addressData = array_filter([
            'city_id'       => $matchedCityId,
            'country_id'    => (int) $countryId,
            'street_number' => $streetNumber,
            'block'         => $block,
            'postal_code'   => $postalCode,
            'address'       => $freeformAddress,
        ], fn($v) => !is_null($v) && $v !== '');

        if (empty($addressData['city_id']) || empty($addressData['address'])) {
            return null;
        }

        return $addressData;
    }

    /**
     * Build canonical Salla POST /admin/v2/orders payload containing all required API fields.
     */
    public function buildCanonicalOrderPayload(Channel $channel, array $checkoutState): ?array
    {
        $phone    = $checkoutState['phone'] ?? $checkoutState['customer_phone'] ?? '';
        $fullName = $checkoutState['full_name'] ?? null;
        $email    = $checkoutState['email'] ?? null;

        $customerId = $this->resolveOrCreateCustomerForChannel($channel, $phone, $fullName, $email);
        if (!$customerId) {
            Log::warning('SallaService: unable to resolve or create customer_id for order creation', [
                'channel_id' => $channel->id,
                'phone'      => $phone,
            ]);
            return null;
        }

        $freeformAddress = $checkoutState['address'] ?? '';
        $shippingAddress = $this->resolveShippingAddressForChannel($channel, $freeformAddress, $phone);

        if (!$shippingAddress) {
            Log::warning('SallaService: shipping address could not be confidently mapped to merchant Salla city/fields', [
                'channel_id' => $channel->id,
                'address'    => $freeformAddress,
            ]);
            return null;
        }

        $productId = (int)($checkoutState['salla_product_id'] ?? null);
        if (!$productId && !empty($checkoutState['product_name'])) {
            try {
                $productsRes = $this->getProductsForChannel($channel, ['per_page' => 20]);
                $items = $productsRes['data'] ?? [];
                $targetName = mb_strtolower(trim($checkoutState['product_name']));

                foreach ($items as $item) {
                    $itemName = !empty($item['name']) ? mb_strtolower(trim($item['name'])) : '';
                    if ($itemName !== '' && (str_contains($itemName, $targetName) || str_contains($targetName, $itemName))) {
                        $productId = (int)$item['id'];
                        Log::info('SallaService: resolved product_id by matching product_name', [
                            'product_name' => $checkoutState['product_name'],
                            'matched_id'   => $productId,
                        ]);
                        break;
                    }
                }

                // If store has only 1 product, fallback to that single product
                if (!$productId && count($items) === 1 && isset($items[0]['id'])) {
                    $productId = (int)$items[0]['id'];
                    Log::info('SallaService: store has single product — using it as fallback for order', [
                        'matched_id' => $productId,
                    ]);
                }
            } catch (\Exception $e) {
                Log::warning('SallaService: product lookup by name failed', ['error' => $e->getMessage()]);
            }
        }

        if (!$productId) {
            Log::warning('SallaService: missing salla_product_id for order creation', [
                'channel_id' => $channel->id,
                'checkout_state' => $checkoutState,
            ]);
            return null;
        }

        $phoneData = $this->normalizePhoneForCustomer($phone);
        $internationalMobile = $phoneData['mobile_code_country'] . $phoneData['mobile'];
        $countryCode = match ($phoneData['mobile_code_country']) {
            '+20' => 'EG', '+966' => 'SA', '+971' => 'AE', '+965' => 'KW',
            '+974' => 'QA', '+973' => 'BH', '+968' => 'OM', '+967' => 'YE',
            '+962' => 'JO', '+961' => 'LB', '+964' => 'IQ', '+1' => 'US',
            '+44' => 'GB', '+90' => 'TR', '+91' => 'IN', '+33' => 'FR',
            '+49' => 'DE', default => 'SA',
        };

        $products = [[
            'identifier_type' => 'id',
            'identifier'      => $productId,
            'quantity'        => 1,
        ]];

        $payment = [
            'method' => 'cod',
            'status' => 'pending',
        ];

        return [
            'customer' => array_filter([
                'id'     => $customerId,
                'name'   => $fullName,
                'mobile' => $internationalMobile,
                'email'  => $email,
            ], fn ($value) => !is_null($value) && $value !== ''),
            'receiver' => array_filter([
                'name'         => $fullName,
                'country_code' => $countryCode,
                'phone'        => ltrim($internationalMobile, '+'),
                'email'        => $email,
                'notify'       => false,
            ], fn ($value) => !is_null($value) && $value !== ''),
            'delivery_method' => 'shipping',
            'ship_to' => array_filter([
                'country'       => $shippingAddress['country_id'] ?? null,
                'city'          => $shippingAddress['city_id'] ?? null,
                'street_number' => $shippingAddress['street_number'] ?? null,
                'block'         => $shippingAddress['block'] ?? null,
                'address'       => $shippingAddress['address'] ?? $freeformAddress,
                'address_line'  => $shippingAddress['address'] ?? $freeformAddress,
                'postal_code'   => $shippingAddress['postal_code'] ?? null,
            ], fn ($value) => !is_null($value) && $value !== ''),
            'products' => $products,
            'payment'  => $payment,
        ];
    }

    /**
     * Create an order in Salla store using channel-aware auto-refresh token.
     */
    public function createOrderForChannel(Channel $channel, array $data = []): array
    {
        if (isset($data['salla_product_id']) || isset($data['address']) || isset($data['full_name'])) {
            $data = $this->buildCanonicalOrderPayload($channel, $data);
        }

        if (empty($data)) {
            Log::error('SALLA_ORDER_CREATE_FAILED', [
                'channel_id' => $channel->id,
                'reason'     => 'Canonical Salla order payload could not be constructed (unmapped city ID, missing customer, or missing product)',
            ]);
            throw new \Exception('Canonical Salla order payload could not be constructed: missing customer, product, or unmapped city ID');
        }

        Log::info('SALLA_ORDER_CREATE_START', ['channel_id' => $channel->id]);
        Log::info('SALLA_ORDER_CREATE_REQUEST', [
            'channel_id' => $channel->id,
            'payload'    => $data,
        ]);

        try {
            $res = $this->apiCallForChannel($channel, 'POST', '/orders', $data);

            Log::info('SALLA_ORDER_CREATE_RESPONSE', [
                'channel_id' => $channel->id,
                'status'     => 200,
                'response'   => $res,
            ]);

            $orderId = (string)($res['data']['reference_id'] ?? $res['data']['id'] ?? $res['id'] ?? null);
            if ($orderId) {
                Log::info('SALLA_ORDER_CREATE_SUCCESS', [
                    'channel_id' => $channel->id,
                    'order_id'   => $orderId,
                ]);
            }

            return $res;
        } catch (\Exception $e) {
            Log::error('SALLA_ORDER_CREATE_FAILED', [
                'channel_id' => $channel->id,
                'error'      => $e->getMessage(),
                'payload'    => $data,
            ]);
            throw $e;
        }
    }

    /**
     * Priority 1 fix: channel-aware (auto-refresh) product LIST endpoint, for
     * aggregate/count queries ("how many products", "list your products").
     * Correct endpoint: GET /admin/v2/products — never a single-resource lookup.
     */
    public function getProductsForChannel(Channel $channel, array $params = []): array
    {
        return $this->apiCallForChannel($channel, 'GET', '/products', $params);
    }

    /**
     * Priority 1 fix: channel-aware (auto-refresh) order LIST endpoint, for
     * aggregate/count queries ("how many orders", "show me my orders").
     * Correct endpoint: GET /admin/v2/orders — never a single-resource lookup.
     */
    public function getOrdersForChannel(Channel $channel, array $params = []): array
    {
        return $this->apiCallForChannel($channel, 'GET', '/orders', $params);
    }

    /**
     * Normalise a raw GET /products list response into a small, AI-safe structure:
     * total count (from pagination metadata, falling back to the returned page size),
     * the returned count, and up to 10 items for display.
     */
    public function formatProductsListForAI(array $productsResponse): array
    {
        $products   = $productsResponse['data'] ?? [];
        $pagination = $productsResponse['pagination'] ?? [];

        return [
            'total_count'    => $pagination['total'] ?? count($products),
            'returned_count' => count($products),
            'per_page'       => $pagination['per_page'] ?? null,
            'current_page'   => $pagination['current_page'] ?? null,
            'items'          => array_map(function ($p) {
                $imageUrl = $this->extractImageUrl($p);
                
                return [
                    'id'       => $p['id']   ?? null,
                    'sku'      => $p['sku']  ?? null,
                    'name'     => $p['name'] ?? 'Unknown',
                    'price'    => $p['price']['amount']       ?? $p['price'] ?? null,
                    'currency' => $p['price']['currency_code'] ?? 'SAR',
                    'quantity' => $p['quantity'] ?? null,
                    'image_url'=> $imageUrl,
                ];
            }, array_slice($products, 0, 10)),
        ];
    }

    /**
     * Safely extract a string image URL from any Salla product structure (handles string & array variants).
     */
    public function extractImageUrl(array $p): ?string
    {
        // Salla product responses expose `thumbnail` and `main_image` as URL
        // strings, and `images` as an array of objects with a `url` field.
        // Some API versions wrap image URLs in nested original/thumbnail data.
        foreach (['thumbnail', 'main_image', 'images', 'image', 'image_url'] as $key) {
            $url = $this->findImageUrl($p[$key] ?? null);
            if ($url) {
                return $url;
            }
        }

        return $this->findImageUrl($p['urls']['image'] ?? null);
    }

    private function findImageUrl(mixed $value): ?string
    {
        if (is_string($value) && preg_match('/^https?:\/\//i', $value)) {
            return $value;
        }

        if (!is_array($value)) {
            return null;
        }

        foreach (['url', 'src', 'link', 'original', 'standard_resolution', 'low_resolution', 'thumbnail'] as $key) {
            if (array_key_exists($key, $value)) {
                $url = $this->findImageUrl($value[$key]);
                if ($url) {
                    return $url;
                }
            }
        }

        foreach ($value as $nested) {
            $url = $this->findImageUrl($nested);
            if ($url) {
                return $url;
            }
        }

        return null;
    }

    /**
     * Normalise a raw GET /orders list response into a small, AI-safe structure.
     */
    public function formatOrdersListForAI(array $ordersResponse): array
    {
        $orders     = $ordersResponse['data'] ?? [];
        $pagination = $ordersResponse['pagination'] ?? [];

        return [
            'total_count'    => $pagination['total'] ?? count($orders),
            'returned_count' => count($orders),
            'per_page'       => $pagination['per_page'] ?? null,
            'current_page'   => $pagination['current_page'] ?? null,
            'items'          => array_map(function ($o) {
                return [
                    'id'           => $o['id'] ?? null,
                    'reference_id' => $o['reference_id'] ?? ($o['id'] ?? null),
                    'status'       => $o['status']['name'] ?? $o['status'] ?? 'Unknown',
                    'total'        => $o['total']['amount']       ?? null,
                    'currency'     => $o['total']['currency']     ?? 'SAR',
                ];
            }, array_slice($orders, 0, 10)),
        ];
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
        $webhookSecret = config('services.salla.webhook_secret', env('SALLA_WEBHOOK_SECRET'));
        if (empty($webhookSecret)) {
            Log::error('SALLA_WEBHOOK_SECRET not configured');
            return false;
        }
        return hash_equals(hash_hmac('sha256', $payload, $webhookSecret), $signature);
    }

    /**
     * Normalize a phone into Salla's national mobile number and calling-code fields.
     */
    protected function normalizePhoneForCustomer(string $phone): array
    {
        $phone = trim($phone);
        if (str_starts_with($phone, '00')) {
            $phone = '+' . substr($phone, 2);
        }
        $digits = preg_replace('/[^0-9]/', '', $phone);

        $callingCodes = [
            '966' => '+966', '971' => '+971', '965' => '+965', '974' => '+974',
            '973' => '+973', '968' => '+968', '967' => '+967', '962' => '+962',
            '961' => '+961', '964' => '+964', '20' => '+20', '1' => '+1',
            '44' => '+44', '90' => '+90', '91' => '+91', '33' => '+33', '49' => '+49',
        ];

        if (str_starts_with($phone, '+')) {
            foreach ($callingCodes as $digitsCode => $formattedCode) {
                if (str_starts_with($digits, $digitsCode)) {
                    return [
                        'mobile_code_country' => $formattedCode,
                        'mobile' => substr($digits, strlen($digitsCode)),
                    ];
                }
            }

            // Unknown international prefix: use the longest plausible 1-3 digit code.
            $codeLength = strlen($digits) >= 12 ? 3 : (strlen($digits) >= 11 ? 2 : 1);
            return [
                'mobile_code_country' => '+' . substr($digits, 0, $codeLength),
                'mobile' => substr($digits, $codeLength),
            ];
        }

        if (str_starts_with($digits, '9665')) {
            return ['mobile_code_country' => '+966', 'mobile' => substr($digits, 3)];
        }
        if (str_starts_with($digits, '20') && strlen($digits) >= 12) {
            return ['mobile_code_country' => '+20', 'mobile' => substr($digits, 2)];
        }
        if (preg_match('/^05[0-9]{8}$/', $digits)) {
            return ['mobile_code_country' => '+966', 'mobile' => substr($digits, 1)];
        }
        if (preg_match('/^01[0-9]{9}$/', $digits)) {
            return ['mobile_code_country' => '+20', 'mobile' => substr($digits, 1)];
        }

        // Default to Saudi calling code because Salla is Saudi-based.
        return ['mobile_code_country' => '+966', 'mobile' => $digits];
    }

    /**
     * Split a full name into first_name and last_name.
     * Handles Arabic names, multiple words, single names, and whitespace.
     */
    protected function splitFullName(string $fullName): array
    {
        $fullName = trim($fullName);
        if (empty($fullName)) {
            return ['first' => 'Customer', 'last' => ''];
        }

        $words = array_filter(explode(' ', $fullName));
        $count = count($words);

        if ($count === 1) {
            return ['first' => $words[0], 'last' => $words[0]];
        }

        // Multiple words: first word is first name, rest is last name
        $firstName = $words[0];
        $lastName = implode(' ', array_slice($words, 1));

        return ['first' => $firstName, 'last' => $lastName];
    }
}
