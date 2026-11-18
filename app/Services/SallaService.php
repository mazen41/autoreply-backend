<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use App\Models\Channel;
use App\Helpers\SallaPhoneHelper;
use App\Exceptions\SallaOrderValidationException;

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
            ]);
            throw new \Exception('Failed to exchange authorization code for token');
        }

        $tokenData = $response->json();

        Log::info('[SALLA OAuth Token Response]', [
            'status'         => $response->status(),
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
        $request = Http::withToken($accessToken)
            ->accept('application/json')
            ->connectTimeout(10)
            ->timeout(30);

        $response = strtoupper($method) === 'GET'
            ? $request->get($url, $data)
            : $request->{strtolower($method)}($url, $data);

        if (!$response->successful()) {
            Log::error("Salla API call failed: {$method} {$url}", [
                'status' => $response->status(),
            ]);

            if ($response->status() === 429) {
                $retryAfter = $response->header('Retry-After', 60);
                throw new \Exception("Rate limited. Retry after {$retryAfter} seconds");
            }

            if ($response->status() === 401) {
                $responseBody = $response->body();

                if ($this->isMissingScopeError($responseBody)) {
                    throw new \Exception('Salla API scope missing');
                }

                throw new \Exception('Access token expired');
            }

            if ($response->status() === 422) {
                // Salla's field-validation errors are authoritative on what a
                // given store/shipping method actually requires. Surface them
                // as structured data instead of a generic failure so a missing
                // ship_to field can be requested from the customer precisely.
                $validationFields = $response->json('fields')
                    ?? $response->json('error.fields')
                    ?? $response->json('errors')
                    ?? $response->json('data.errors')
                    ?? [];
                throw new SallaOrderValidationException(
                    "API call failed: 422",
                    is_array($validationFields) ? $validationFields : []
                );
            }

            throw new \Exception("API call failed: {$response->status()}");
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
                    'exception_type' => get_class($e),
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
                            'exception_type' => get_class($refreshEx),
                        ]);

                        // The token refresh succeeded, but its grant still lacks the needed scope.
                        // Preserve the refreshed credentials and connected status.
                        throw $refreshEx;
                    }

                    Log::error('Salla: token refresh failed — marking channel as token_expired', [
                        'channel_id' => $channel->id,
                        'exception_type' => get_class($refreshEx),
                    ]);
                    $channel->update(['status' => 'token_expired']);
                    throw new \Exception("Salla token refresh failed for channel {$channel->id}: " . $refreshEx->getMessage());
                }
            }

            throw $e;
        }
    }

    /** Fetch shared Salla country metadata with this store's authenticated token. */
    public function getCountriesForChannel(Channel $channel): array
    {
        return $this->apiCallForChannel($channel, 'GET', '/countries');
    }

    /** Fetch one page of a Salla country city catalog (the API currently caps at 15). */
    public function getCitiesPageForChannel(Channel $channel, int $countryId, int $page): array
    {
        return $this->apiCallForChannel($channel, 'GET', "/countries/{$countryId}/cities", [
            'page' => $page,
            'per_page' => 15,
            'count' => 15,
        ]);
    }

    /**
     * Salla uses 401 for both expired tokens and insufficient OAuth scopes.
     * Missing scopes require reconnecting the store, not marking its token expired.
     */
    protected function isMissingScopeError(string $message): bool
    {
        $message = strtolower($message);
        return str_contains($message, 'should have access to one of those scopes')
            || str_contains($message, 'salla api scope missing');
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
                'store_id' => $response['data']['id'] ?? null,
            ]);
            return $response['data'];
        }

        // Fallback: response itself is the store object
        if (isset($response['id'])) {
            return $response;
        }

        Log::error('Salla getStoreInfo: unexpected response shape', ['response_keys' => array_keys($response)]);
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
                        'phone_present' => !empty($candidate['mobile']),
                        'customer_id'    => $candidate['id'] ?? 'N/A',
                    ]);
                    continue;
                }

                if ($storedLast9 === $last9) {
                    $customer = $candidate;
                    Log::info('Salla: customer phone verified', [
                        'phone_match' => true,
                        'customer_id' => $candidate['id'],
                    ]);
                    break 2; // found a verified match — stop searching
                }

                Log::warning('Salla: customer phone mismatch — skipping', [
                    'phone_match' => false,
                    'customer_id'     => $candidate['id'] ?? 'N/A',
                ]);
            }
        }

        if (!$customer) {
            Log::info('Salla: no verified customer found for phone', [
                'phone_present' => $cleanPhone !== '',
                'search_variant_count' => count($acceptedVariants),
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
                    'channel_id' => $channel->id,
                    'phone_match' => false,
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
        $existingCustomer = $this->findCustomerForChannel($channel, $phone);
        if ($existingCustomer && !empty($existingCustomer['id'])) {
            Log::info('SallaService: found existing Salla customer_id', ['customer_id' => $existingCustomer['id']]);
            return (int) $existingCustomer['id'];
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
            Log::warning('SallaService: POST /customers failed', ['exception_type' => get_class($e)]);
        }

        return null;
    }

    /** Find the exact Salla customer matching a phone number and return its saved profile fields. */
    public function findCustomerForChannel(Channel $channel, string $phone): ?array
    {
        $cleanPhone = preg_replace('/[^0-9]/', '', $phone);
        if (empty($cleanPhone)) {
            return null;
        }

        $last9 = substr($cleanPhone, -9);
        $local = '0' . $last9;
        foreach (array_unique([$cleanPhone, $local]) as $searchPhone) {
            try {
                $res = $this->apiCallForChannel($channel, 'GET', '/customers', ['keyword' => $searchPhone]);
                foreach (($res['data'] ?? []) as $candidate) {
                    $candidatePhone = preg_replace('/[^0-9]/', '', (string) ($candidate['mobile'] ?? ''));
                    if (
                        !empty($candidate['id'])
                        && $candidatePhone !== ''
                        && substr($candidatePhone, -9) === $last9
                    ) {
                        return $candidate;
                    }
                }
            } catch (\Exception $e) {
                Log::warning('SallaService: customer profile lookup failed', [
                    'channel_id' => $channel->id,
                    'exception_type' => get_class($e),
                ]);
            }
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
                'exception_type' => get_class($e),
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
                'exception_type' => get_class($e),
            ]);
        }

        return $response;
    }

    /**
     * Compatibility wrapper: synchronize into the shared country catalog, never
     * into a store-specific city table.
     */
    public function syncCityCatalog(
        Channel $channel,
        string $countryCode,
        ?callable $onProgress = null,
        int $startPage = 1
    ): int {
        if ($startPage !== 1) {
            throw new \InvalidArgumentException('Shared catalog sync resumes from its saved country cursor.');
        }
        return app(SharedSallaCityCatalogService::class)->syncAll(
            $countryCode,
            (int) $channel->id,
            $onProgress
        );
    }

    /** Retained for older callers; health is country-wide, not store-specific. */
    public function hasHealthyCityCatalog(Channel $channel, string $countryCode): bool
    {
        return app(SharedSallaCityCatalogService::class)->isHealthy($countryCode);
    }
    public function normalizeCitySearchText(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = strtr($value, [
            'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ى' => 'ي', 'ة' => 'ه',
        ]);
        $value = preg_replace('/[^\\p{L}\\p{N}\\s]/u', ' ', $value) ?? $value;
        $value = preg_replace('/\\s+/u', ' ', $value) ?? $value;
        $value = trim($value);

        // Salla names may include the common transliterated prefixes "Al"/"El"
        // while customers usually write the shorter city name (e.g. "Giza").
        return preg_replace('/^(?:al|el)\\s+/u', '', $value) ?? $value;
    }

    /**
     * Return known spelling/transliteration variants for a canonical Salla city.
     * Keep this deliberately explicit: fuzzy matching is only a fallback and
     * must never turn a similar-looking place into a different delivery city.
     */
    protected function cityNameSearchVariants(string $cityName): array
    {
        $normalized = $this->normalizeCitySearchText($cityName);
        $groups = [
            ['mecca', 'makkah', 'makka', 'makkah al mukarramah', 'makkah mukarramah', 'مكه', 'مكه المكرمه'],
            ['jeddah', 'jedda', 'jidda', 'jiddah', 'جده'],
            ['medina', 'madinah', 'madina', 'al madinah', 'المدينه', 'المدينه المنوره'],
            ['riyadh', 'riyad', 'الرياض'],
            ['dammam', 'ad dammam', 'الدمام'],
        ];

        foreach ($groups as $group) {
            $normalizedGroup = array_map(fn (string $name) => $this->normalizeCitySearchText($name), $group);
            if (in_array($normalized, $normalizedGroup, true)) {
                return array_values(array_unique($normalizedGroup));
            }
        }

        return $normalized === '' ? [] : [$normalized];
    }

    /**
     * Use the country/name indexes to find exact and known-alias candidates
     * before falling back to a full-country fuzzy scan.
     */
    protected function findIndexedCityCandidates(int $countryId, string $normalizedAddress)
    {
        $tokens = preg_split('/\s+/u', $normalizedAddress, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $tokens = array_slice($tokens, 0, 80);
        $names = [];
        $tokenCount = count($tokens);
        for ($start = 0; $start < $tokenCount; $start++) {
            for ($length = 1; $length <= 8 && $start + $length <= $tokenCount; $length++) {
                $phrase = implode(' ', array_slice($tokens, $start, $length));
                foreach ($this->cityNameSearchVariants($phrase) as $variant) {
                    if ($variant !== '') {
                        $names[$variant] = true;
                    }
                }
            }
        }

        if ($names === []) {
            return collect();
        }

        $names = array_keys($names);
        return DB::table('salla_city_reference_cities')
            ->where('country_id', $countryId)
            ->where(function ($query) use ($names): void {
                $query->whereIn('normalized_name', $names)
                    ->orWhereIn('normalized_en', $names)
                    ->orWhereIn('normalized_ar', $names);
            })
            ->get();
    }

    /** Find a unique close Latin spelling when no exact/known alias matched. */
    protected function findFuzzyCityMatch($cities, string $normalizedAddress): ?int
    {
        $addressTokens = preg_split('/\\s+/u', $normalizedAddress, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $bestDistance = PHP_INT_MAX;
        $bestCityIds = [];

        foreach ($cities as $city) {
            $cityDistance = PHP_INT_MAX;
            foreach ([$city->name, $city->name_en, $city->name_ar] as $name) {
                $normalizedName = $this->normalizeCitySearchText(trim((string) $name));
                foreach (preg_split('/\\s+/u', $normalizedName, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $cityToken) {
                    // Edit distance is byte-oriented in PHP, so use it only for
                    // Latin words. Arabic variants are handled by normalization
                    // and the explicit alias groups above.
                    if (mb_strlen($cityToken) < 5 || !preg_match('/^[a-z]+$/', $cityToken)) {
                        continue;
                    }
                    foreach ($addressTokens as $addressToken) {
                        if (mb_strlen($addressToken) < 5 || !preg_match('/^[a-z]+$/', $addressToken)) {
                            continue;
                        }
                        $distance = levenshtein($cityToken, $addressToken);
                        $maxDistance = mb_strlen($cityToken) >= 9 ? 2 : 1;
                        if ($distance <= $maxDistance) {
                            $cityDistance = min($cityDistance, $distance);
                        }
                    }
                }
            }

            if ($cityDistance < $bestDistance) {
                $bestDistance = $cityDistance;
                $bestCityIds = [(int) $city->salla_city_id];
            } elseif ($cityDistance === $bestDistance && $cityDistance !== PHP_INT_MAX) {
                $bestCityIds[] = (int) $city->salla_city_id;
            }
        }

        $bestCityIds = array_values(array_unique($bestCityIds));
        return count($bestCityIds) === 1 && $bestDistance !== PHP_INT_MAX
            ? $bestCityIds[0]
            : null;
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
        $countryCode = SallaPhoneHelper::getCountryCode($phone);

        if (!$countryCode) {
            $addressLower = mb_strtolower($freeformAddress);
            foreach ([
                'EG' => ['egypt', 'مصر'], 'SA' => ['saudi arabia', 'ksa', 'السعودية'],
                'AE' => ['united arab emirates', 'uae', 'الإمارات'], 'KW' => ['kuwait', 'الكويت'],
                'QA' => ['qatar', 'قطر'], 'BH' => ['bahrain', 'البحرين'], 'OM' => ['oman', 'عمان'],
                'YE' => ['yemen', 'اليمن'], 'IQ' => ['iraq', 'العراق'], 'SY' => ['syria', 'سوريا'],
                'JO' => ['jordan', 'الأردن'], 'LB' => ['lebanon', 'لبنان'], 'PS' => ['palestine', 'فلسطين'],
                'LY' => ['libya', 'ليبيا'], 'TN' => ['tunisia', 'تونس'], 'DZ' => ['algeria', 'الجزائر'],
                'MA' => ['morocco', 'المغرب'], 'SD' => ['sudan', 'السودان'], 'SO' => ['somalia', 'الصومال'],
                'DJ' => ['djibouti', 'جيبوتي'], 'KM' => ['comoros', 'جزر القمر'], 'MR' => ['mauritania', 'موريتانيا'],
            ] as $code => $aliases) {
                foreach ($aliases as $alias) {
                    if (mb_stripos($addressLower, mb_strtolower($alias)) !== false) {
                        $countryCode = $code;
                        break 2;
                    }
                }
            }
        }

        if (!$countryCode) {
            Log::info('SallaService: country could not be determined for shipping address', [
                'channel_id' => $channel->id,
            ]);
            return null;
        }

        $catalogs = app(SharedSallaCityCatalogService::class);
        $catalogHealthy = $catalogs->isHealthy($countryCode);
        if (!$catalogHealthy) {
            try {
                $catalogs->queueIfStale($countryCode);
            } catch (\Throwable $exception) {
                Log::warning('Shared Salla city catalog recovery could not be queued', [
                    'channel_id' => $channel->id,
                    'country_code' => $countryCode,
                    'exception_type' => get_class($exception),
                ]);
            }
        }

        $catalog = DB::table('salla_city_catalogs')->where('country_code', $countryCode)->first();
        $countryId = $catalog->country_id ?? null;
        $matchedCityId = null;
        $matchedCityNameLength = 0;
        $normalizedAddress = $this->normalizeCitySearchText($freeformAddress);
        $cities = $catalogHealthy && $countryId
            ? $this->findIndexedCityCandidates((int) $countryId, $normalizedAddress)
            : collect();

        foreach ($cities as $city) {
            foreach ([$city->name, $city->name_en, $city->name_ar] as $cityName) {
                $cityName = trim((string) $cityName);
                if ($cityName === '') {
                    continue;
                }

                foreach ($this->cityNameSearchVariants($cityName) as $normalizedCityName) {
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
        }

        if (!$matchedCityId) {
            $cities = $catalogHealthy && $countryId
                ? DB::table('salla_city_reference_cities')->where('country_id', $countryId)->get()
                : collect();
            $matchedCityId = $this->findFuzzyCityMatch($cities, $normalizedAddress);
        }

        // STRICT RULE 1: If city cannot be matched against merchant's Salla city list, DO NOT fake city_id = 1! Return null.
        if (!$matchedCityId) {
            Log::info('SallaService: could not match city in address against merchant Salla cities', [
                'channel_id' => $channel->id,
                'country_id' => (int) $countryId,
                'country_code' => $countryCode,
                'address'    => $freeformAddress,
                'catalog_synced' => $catalogHealthy,
                'cities_checked' => $catalogHealthy ? (int) ($catalog->saved_city_count ?? 0) : 0,
                'available_city_names_sample' => $cities->take(30)->map(fn ($city) => $city->name)->values()->all(),
            ]);
            return null;
        }

        // Only accept a real street/building number. In particular, never treat
        // a PO Box number as the street number sent to Salla.
        $streetNumber = OrderCheckoutService::extractBuildingNumber($freeformAddress);

        // 3. Parse block/district — only set when an explicit label is present.
        // Do NOT fall back to first-N-words: Salla rejects invented block values and the
        // validation failure cascades into a misleading receiver.phone error.
        $block = null;
        if (preg_match('/(?:block|blk|بلوك|قطعة)\s*[:\-]?\s*([A-Za-z\x{0600}-\x{06FF}\s]{2,30})/ui', $freeformAddress, $bm)) {
            $block = trim($bm[1]);
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
        $checkoutState = SallaAddressParser::fillMissing($checkoutState);
        $phone    = $checkoutState['phone'] ?? $checkoutState['customer_phone'] ?? '';
        $fullName = $checkoutState['full_name'] ?? null;
        $email    = $checkoutState['email'] ?? $checkoutState['customer_email'] ?? null;

        $existingCustomer = empty($email) ? $this->findCustomerForChannel($channel, $phone) : null;
        if (empty($email) && !empty($existingCustomer['email'])) {
            $email = $existingCustomer['email'];
        }

        if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Log::info('SallaService: ignoring invalid optional customer email', [
                'channel_id' => $channel->id,
            ]);
            $email = null;
        }

        // Validate before customer creation so a malformed checkout phone never
        // creates an orphan customer with a null/incorrect calling code.
        $phoneNormalized = SallaPhoneHelper::normalize($phone);
        if ($phoneNormalized === null) {
            Log::info('SallaService: phone country could not be determined for customer', [
                'channel_id' => $channel->id,
            ]);
            return null;
        }

        if (!SallaPhoneHelper::isValidFormat($phone)) {
            Log::warning('SallaService: phone number has invalid format for order creation', [
                'channel_id' => $channel->id,
            ]);
            return null;
        }

        $freeformAddress = $checkoutState['address'] ?? '';
        $countryCode = SallaPhoneHelper::getCountryCode($phone) ?? 'SA';
        $shippingAddress = $this->resolveShippingAddressForChannel($channel, $freeformAddress, $phone);

        if (!$shippingAddress) {
            Log::warning('SallaService: shipping address could not be confidently mapped to merchant Salla city/fields', [
                'channel_id' => $channel->id,
                'address'    => $freeformAddress,
            ]);
            return null;
        }

        $postalCode = $checkoutState['postal_code'] ?? $shippingAddress['postal_code'] ?? null;
        $coordinates = $checkoutState['geo_coordinates'] ?? null;
        $buildingNumber = $checkoutState['building_number'] ?? ($countryCode === 'SA' ? null : ($shippingAddress['street_number'] ?? null));
        $streetNumber = $checkoutState['street_number'] ?? ($countryCode === 'SA' ? null : ($shippingAddress['street_number'] ?? $buildingNumber));
        $block = $checkoutState['block'] ?? $shippingAddress['block'] ?? null;

        // Saudi National Address values come from explicit customer input. Do
        // not substitute city-centroid geocoding or reuse the building number
        // as the street number.

        $rawLineItems = $checkoutState['salla_line_items'] ?? [];
        if (!is_array($rawLineItems)) {
            return null;
        }
        $normalizedLineItems = [];
        if ($rawLineItems !== []) {
            $seenLineItemIds = [];
            foreach ($rawLineItems as $index => $lineItem) {
                if (!is_array($lineItem)) {
                    return null;
                }
                $lineItemId = (string) ($lineItem['line_item_id'] ?? $index);
                $lineProductId = filter_var($lineItem['salla_product_id'] ?? $lineItem['product_id'] ?? null, FILTER_VALIDATE_INT);
                $quantity = filter_var($lineItem['quantity'] ?? 1, FILTER_VALIDATE_INT);
                if ($lineItemId === '' || isset($seenLineItemIds[$lineItemId]) || !$lineProductId || $lineProductId < 1
                    || !$quantity || $quantity < 1 || $quantity > 100
                    || !isset($lineItem['price']) || !is_numeric($lineItem['price']) || (float) $lineItem['price'] < 0) {
                    Log::warning('SallaService: invalid canonical line item; refusing order payload', [
                        'channel_id' => $channel->id,
                        'line_item_index' => $index,
                    ]);
                    return null;
                }
                $seenLineItemIds[$lineItemId] = true;
                $optionMap = $lineItem['options'] ?? $lineItem['salla_options'] ?? [];
                if (!is_array($optionMap)) {
                    return null;
                }
                $normalizedOptions = [];
                foreach ($optionMap as $optionId => $valueIds) {
                    $validOptionId = filter_var($optionId, FILTER_VALIDATE_INT);
                    if (!$validOptionId || $validOptionId < 1) {
                        return null;
                    }
                    $values = array_values(array_unique(array_filter(array_map('strval', (array) $valueIds), static fn ($value) => $value !== '')));
                    if ($values !== []) {
                        $normalizedOptions[(string) $validOptionId] = $values;
                    }
                }
                $normalizedLineItems[] = [
                    'line_item_id' => $lineItemId,
                    'product_id' => (int) $lineProductId,
                    'quantity' => (int) $quantity,
                    'price' => (float) $lineItem['price'],
                    'name' => trim((string) ($lineItem['product_name'] ?? $lineItem['name'] ?? 'Product')),
                    'options' => $normalizedOptions,
                ];
            }
        }

        $productId = (int)($checkoutState['salla_product_id'] ?? null);
        if ($normalizedLineItems === [] && !$productId && !empty($checkoutState['product_name'])) {
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
                Log::warning('SallaService: product lookup by name failed', ['exception_type' => get_class($e)]);
            }
        }

        if ($normalizedLineItems === [] && !$productId) {
            Log::warning('SallaService: missing salla_product_id for order creation', [
                'channel_id' => $channel->id,
                'checkout_state' => $checkoutState,
            ]);
            return null;
        }

        // Ask Salla for valid delivery options for the resolved city and the
        // customer's actual postal/geographic location. courier_id is a store
        // shipping company ID; the estimate's own ID is a shipping-details ID.
        $estimateParams = [
            'city_id' => $shippingAddress['city_id'],
            'country_id' => $shippingAddress['country_id'],
            'postal_code' => $postalCode,
        ];
        if (!empty($coordinates['lat']) && !empty($coordinates['lng'])) {
            $estimateParams['geocode'] = $coordinates['lat'] . ',' . $coordinates['lng'];
        }
        $ratesResponse = $this->apiCallForChannel($channel, 'GET', '/shipping/companies/estimate-rate', $estimateParams);
        $rates = $ratesResponse['data'] ?? [];
        $selectedRate = null;
        foreach ($rates as $rate) {
            if (empty($rate['company_id'])) {
                continue;
            }
            $hasCod = collect($rate['services'] ?? [])->contains(function ($service) {
                return strtolower((string) ($service['name'] ?? '')) === 'cod';
            });
            if ($hasCod) {
                $selectedRate = $rate;
                break;
            }
        }

        if (!$selectedRate) {
            Log::warning('SallaService: no COD shipping estimate available for the delivery address', [
                'channel_id' => $channel->id,
                'city_id' => $shippingAddress['city_id'],
                'rate_count' => count($rates),
            ]);
            return null;
        }

        $districtId = null;
        $districtName = trim((string) ($checkoutState['district'] ?? ''));
        if ($countryCode === 'SA' && $districtName !== '') {
            $districtId = $this->resolveDistrictIdForChannel($channel, (int) $shippingAddress['city_id'], $districtName);
        }
        if ($countryCode === 'SA' && $districtName !== '' && !$districtId) {
            // A free-form address segment may not be a Salla district name
            // (for example, a street or neighborhood alias). District is
            // optional unless this store's own order endpoint rejects the
            // payload; never prevent an otherwise valid order locally here.
            Log::warning('SallaService: unmatched optional Saudi district omitted; Salla will validate whether one is required', [
                'channel_id' => $channel->id,
                'city_id' => (int) $shippingAddress['city_id'],
            ]);
        }

        // Create/resolve the customer only after the address, product, COD
        // shipping rate, and provider-required fields are all ready.
        $customerId = $this->resolveOrCreateCustomerForChannel($channel, $phone, $fullName, $email);
        if (!$customerId) {
            Log::warning('SallaService: unable to resolve or create customer_id for order creation', [
                'channel_id' => $channel->id,
                'phone_present' => $phone !== '',
            ]);
            return null;
        }

        // Current docs example omits '+', but Salla Support reproduced rejection
        // without it and acceptance with it for this store. Preserve their confirmed rule.
        $receiverPhone = SallaPhoneHelper::formatForReceiver($phone);
        // customer.mobile: full number WITH leading '+' (separate Salla field)
        $customerMobile = SallaPhoneHelper::formatForCustomerMobile($phone);
        // ISO country code from phone, fallback to SA for Salla-based stores

        $products = [];
        if ($normalizedLineItems !== []) {
            foreach ($normalizedLineItems as $lineItem) {
                $product = [
                    'identifier_type' => 'id',
                    'identifier' => $lineItem['product_id'],
                    'quantity' => $lineItem['quantity'],
                ];
                if ($lineItem['options'] !== []) {
                    $product['options'] = [];
                    foreach ($lineItem['options'] as $optionId => $valueIds) {
                        $product['options'][] = ['id' => (int) $optionId, 'value' => $valueIds];
                    }
                }
                $products[] = $product;
            }
        } else {
            $products = [[
                'identifier_type' => 'id',
                'identifier' => $productId,
                'quantity' => 1,
            ]];
            $selectedOptions = $checkoutState['salla_options'] ?? [];
            if (is_array($selectedOptions) && $selectedOptions !== []) {
                $products[0]['options'] = array_values(array_filter(array_map(
                    static function ($optionId, $valueIds): ?array {
                        $validOptionId = filter_var($optionId, FILTER_VALIDATE_INT);
                        $values = array_values(array_unique(array_filter(array_map('strval', (array) $valueIds), static fn ($value) => $value !== '')));
                        return !$validOptionId || $values === [] ? null : ['id' => (int) $validOptionId, 'value' => $values];
                    },
                    array_keys($selectedOptions),
                    array_values($selectedOptions)
                )));
                if ($products[0]['options'] === []) {
                    unset($products[0]['options']);
                }
            }
        }

        $currency = $checkoutState['product_currency'] ?? $selectedRate['total']['currency'] ?? 'SAR';
        $productAmount = $normalizedLineItems !== []
            ? array_sum(array_map(static fn (array $lineItem): float => $lineItem['price'] * $lineItem['quantity'], $normalizedLineItems))
            : (float) ($checkoutState['product_price'] ?? 0);
        $shippingAmount = (float) ($selectedRate['total']['amount'] ?? 0);
        $codServiceAmount = 0.0;
        foreach (($selectedRate['services'] ?? []) as $service) {
            if (strtolower((string) ($service['name'] ?? '')) === 'cod') {
                $codServiceAmount = (float) ($service['amount']['amount'] ?? 0);
                break;
            }
        }

        $payment = [
            'method' => 'cod',
            'status' => 'pending_payment',
            'cash_on_delivery' => [
                'amount' => $productAmount + $shippingAmount + $codServiceAmount,
                'currency' => $currency,
            ],
        ];

        $shipTo = [
            'country' => $shippingAddress['country_id'] ?? null,
            'city' => $shippingAddress['city_id'] ?? null,
            'district' => $districtId,
            'street_number' => $streetNumber,
            'block' => $block,
            'address' => $shippingAddress['address'] ?? $freeformAddress,
            'address_line' => $checkoutState['address_line'] ?? $shippingAddress['address'] ?? $freeformAddress,
            'postal_code' => $postalCode,
            'geo_coordinates' => isset($coordinates['lat'], $coordinates['lng']) ? ['lat' => (float) $coordinates['lat'], 'lng' => (float) $coordinates['lng']] : null,
            'building_number' => $buildingNumber,
            'short_address' => $checkoutState['short_address'] ?? null,
            'additional_number' => $checkoutState['additional_number'] ?? null,
        ];
        foreach (['block', 'postal_code', 'additional_number'] as $nullableField) {
            if (is_string($shipTo[$nullableField])
                && in_array(mb_strtolower(trim($shipTo[$nullableField])), ['__not_applicable__', 'n/a', 'na', 'none', 'not applicable', 'لا يوجد', 'غير متوفر'], true)) {
                $shipTo[$nullableField] = null;
            }
        }
        // Salla's validation rejects `null` for ship_to.block on some stores/couriers even though
        // the field is logically optional for the customer. Rather than fail checkout, substitute a
        // safe non-empty placeholder so the order can still be created.
        if ($shipTo['block'] === null) {
            $shipTo['block'] = '-';
        }
        if ($districtId === null) {
            unset($shipTo['district']);
        }
        if ($countryCode !== 'SA') {
            $shipTo = array_filter($shipTo, fn ($value) => !is_null($value) && $value !== '');
        }

        return [
            'customer' => array_filter([
                'id'     => $customerId,
                'name'   => $fullName,
                'mobile' => $customerMobile,
                'email'  => $email,
            ], fn ($value) => !is_null($value) && $value !== ''),
            'receiver' => array_filter([
                'name'         => $fullName,
                'country_code' => $countryCode,
                'phone'        => $receiverPhone,
                'email'        => $email,
                'notify'       => false,
            ], fn ($value) => !is_null($value) && $value !== ''),
            'delivery_method' => 'shipping',
            'courier_id' => (int) $selectedRate['company_id'],
            'ship_to' => $shipTo,
            'products' => $products,
            'payment'  => $payment,
        ];
    }

    /** Resolve a customer-provided district name to Salla's numeric district ID. */
    protected function resolveDistrictIdForChannel(Channel $channel, int $cityId, string $districtName): ?int
    {
        $endpoint = "/cities/{$cityId}/districts";
        $cacheKey = "salla_districts_channel_{$channel->id}_city_{$cityId}";
        $catalog = $this->cachedSallaResponse($cacheKey, $endpoint, function () use ($channel, $endpoint) {
            $districts = [];
            $page = 1;
            $totalPages = 1;

            do {
                $response = $this->apiCallForChannel($channel, 'GET', $endpoint, [
                    'page' => $page,
                    'per_page' => 60,
                ]);
                $districts = array_merge($districts, $response['data'] ?? []);
                $pagination = $response['pagination'] ?? [];
                $totalPages = max($totalPages, (int) ($pagination['totalPages'] ?? $pagination['total_pages'] ?? 1));
                $hasNext = $page < $totalPages || !empty($pagination['links']['next']);
                $page++;

                if ($page > 200) {
                    Log::warning('SallaService: district pagination exceeded the safety limit', [
                        'channel_id' => $channel->id,
                        'city_id' => $cityId,
                    ]);
                    break;
                }
            } while ($hasNext);

            return ['data' => $districts];
        });

        $requestedName = $this->normalizeCitySearchText(preg_replace('/^(?:district|neighborhood|حي)\s+/iu', '', $districtName) ?? $districtName);
        foreach ($catalog['data'] ?? [] as $district) {
            if (empty($district['id'])) {
                continue;
            }
            foreach ([$district['name'] ?? '', $district['name_en'] ?? ''] as $candidate) {
                $candidateName = $this->normalizeCitySearchText(preg_replace('/\s+dist\.?$/iu', '', (string) $candidate) ?? (string) $candidate);
                if ($requestedName !== '' && $requestedName === $candidateName) {
                    return (int) $district['id'];
                }
            }
        }

        return null;
    }

    /**
     * Create an order in Salla store using channel-aware auto-refresh token.
     */
    public function createOrderForChannel(Channel $channel, array $data = []): array
    {
        if (isset($data['salla_product_id']) || isset($data['address']) || isset($data['full_name'])) {
            $grantedScopes = $channel->metadata['scopes'] ?? null;
            if (is_string($grantedScopes) && trim($grantedScopes) !== '') {
                $granted = preg_split('/[\s,]+/', trim($grantedScopes)) ?: [];
                if (!in_array('orders.read_write', $granted, true)) {
                    throw new \RuntimeException('Salla authorization is missing orders.read_write. Reconnect the store with order access enabled.');
                }
            }

            $phone = (string) ($data['phone'] ?? $data['customer_phone'] ?? '');
            $address = (string) ($data['address'] ?? $data['customer_address'] ?? '');
            if (!$this->resolveShippingAddressForChannel($channel, $address, $phone)) {
                return ['status' => 'needs_fields', 'missing_fields' => ['delivery_city']];
            }

            // Pre-submit check: ask for missing required fields before any Salla request.
            $presubmitMissing = SallaOrderValidator::missingFields(
                $data,
                (array) ($data['salla_required_fields'] ?? [])
            );
            if ($presubmitMissing !== []) {
                Log::info('SALLA_PRESUBMIT_MISSING_FIELDS', [
                    'channel_id' => $channel->id,
                    'missing_fields' => $presubmitMissing,
                ]);
                return ['status' => 'needs_fields', 'missing_fields' => $presubmitMissing];
            }

            $data = $this->buildCanonicalOrderPayload($channel, $data);
            if ($data === null) {
                Log::warning('Salla order payload preflight failed before order request', [
                    'channel_id' => $channel->id,
                ]);
                return ['status' => 'needs_fields', 'missing_fields' => ['delivery_city']];
            }
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
            'field_names' => array_keys($data),
        ]);

        try {
            try {
                $res = $this->apiCallForChannel($channel, 'POST', '/orders', $data);
            } catch (SallaOrderValidationException $e) {
                throw $e;
            } catch (\Throwable $e) {
                $status = null;
                if (preg_match('/API call failed: (?:HTTP\s*)?(\d{3})\b/', $e->getMessage(), $statusMatch)) {
                    $status = (int) $statusMatch[1];
                }
                throw new \App\Exceptions\SallaOrderSubmissionException(
                    \App\Services\OrderCheckoutService::maskSensitive($e->getMessage()),
                    $status,
                    $e
                );
            }

            Log::info('SALLA_ORDER_CREATE_RESPONSE', [
                'channel_id' => $channel->id,
                'status'     => 200,
                'has_order_id' => !empty($res['data']['reference_id'] ?? $res['data']['id'] ?? $res['id'] ?? null),
            ]);

            $orderId = (string)($res['data']['reference_id'] ?? $res['data']['id'] ?? $res['id'] ?? null);
            if ($orderId) {
                Log::info('SALLA_ORDER_CREATE_SUCCESS', [
                    'channel_id' => $channel->id,
                    'order_id'   => $orderId,
                ]);
            }

            return $res;
        } catch (SallaOrderValidationException $e) {
            // Map Salla's own field names to our checkout_state field names so
            // the customer is asked only for what THIS store genuinely needs.
            $fieldMap = [
                'receiver.email' => 'email', 'customer.email' => 'email', 'email' => 'email',
                'receiver.name' => 'full_name', 'customer.name' => 'full_name',
                'receiver.phone' => 'phone', 'customer.mobile' => 'phone',
                'ship_to.postal_code' => 'postal_code', 'postal_code' => 'postal_code',
                'ship_to.building_number' => 'building_number', 'building_number' => 'building_number',
                'ship_to.street_number' => 'street_number', 'street_number' => 'street_number',
                'ship_to.block' => 'block', 'block' => 'block',
                'ship_to.district' => 'district', 'district' => 'district',
                'ship_to.geo_coordinates' => 'geo_coordinates', 'geo_coordinates' => 'geo_coordinates',
                'ship_to.short_address' => 'short_address', 'short_address' => 'short_address',
                'ship_to.additional_number' => 'additional_number', 'additional_number' => 'additional_number',
                'ship_to.address' => 'address', 'ship_to.address_line' => 'address',
            ];
            $missingFields = [];
            $flattenFieldNames = function (array $fields, string $prefix = '') use (&$flattenFieldNames): array {
                $names = [];
                foreach ($fields as $field => $details) {
                    $path = $prefix === '' ? (string) $field : $prefix . '.' . (string) $field;
                    if (is_array($details)) {
                        if ($details === [] || array_is_list($details)) {
                            $names[] = $path;
                        } else {
                            $names = array_merge($names, $flattenFieldNames($details, $path));
                        }
                    } elseif ($prefix !== '' || !is_numeric($field)) {
                        $names[] = $path;
                    }
                }

                return $names;
            };
            foreach ($flattenFieldNames($e->fields) as $sallaField) {
                $normalized = strtolower((string) $sallaField);
                if (isset($fieldMap[$normalized])) {
                    $missingFields[] = $fieldMap[$normalized];
                }
            }
            $missingFields = array_values(array_unique($missingFields));

            Log::warning('SALLA_ORDER_CREATE_VALIDATION_FAILED', [
                'channel_id' => $channel->id,
                'salla_fields' => array_keys($e->fields),
                'mapped_missing_fields' => $missingFields,
            ]);

            if ($missingFields !== []) {
                return ['status' => 'needs_fields', 'missing_fields' => $missingFields];
            }

            throw $e;
        } catch (\Exception $e) {
            Log::error('SALLA_ORDER_CREATE_FAILED', [
                'channel_id' => $channel->id,
                'exception_type' => get_class($e),
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

    /** Return normalized variant/required options for one product in this Salla store. */
    public function getProductOptionsForChannel(Channel $channel, string $productId): array
    {
        $product = $this->apiCallForChannel($channel, 'GET', '/products/' . rawurlencode($productId));
        $options = $product['data']['options'] ?? [];
        if (!is_array($options)) {
            return [];
        }

        return collect($options)
            ->filter(fn ($option) => is_array($option)
                && (($option['purpose'] ?? null) === 'variants' || !empty($option['required'])))
            ->map(function (array $option): array {
                $values = collect($option['values'] ?? [])
                    ->filter(fn ($value) => is_array($value) && empty($value['is_out_of_stock']))
                    ->map(fn ($value) => [
                        'id' => (string) ($value['id'] ?? ''),
                        'name' => trim((string) ($value['name'] ?? '')),
                    ])
                    ->filter(fn ($value) => $value['id'] !== '' && $value['name'] !== '')
                    ->values()
                    ->all();

                return [
                    'id' => (string) ($option['id'] ?? ''),
                    'name' => trim((string) ($option['name'] ?? 'Product option')),
                    'values' => $values,
                ];
            })
            ->filter(fn ($option) => $option['id'] !== '' && $option['values'] !== [])
            ->values()
            ->all();
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
     * Channel-aware (auto-refresh) customer LIST endpoint.
     * Correct endpoint: GET /admin/v2/customers
     */
    public function getCustomersForChannel(Channel $channel, array $params = []): array
    {
        return $this->apiCallForChannel($channel, 'GET', '/customers', $params);
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
        $normalized = SallaPhoneHelper::normalize($phone);
        if ($normalized !== null) {
            return [
                'mobile_code_country' => $normalized['calling_code'],
                'mobile' => $normalized['national'],
            ];
        }

        // Fallback for unparseable phone
        $digits = preg_replace('/[^0-9]/', '', $phone);
        return ['mobile_code_country' => null, 'mobile' => $digits];
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
