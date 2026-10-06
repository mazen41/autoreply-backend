<?php

namespace App\Http\Controllers;

use App\Jobs\SyncCommerceStore;
use App\Models\Channel;
use App\Models\CommerceOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ShopifyController extends Controller
{
    public function connect(Request $request)
    {
        $validated = $request->validate(['shop_domain' => ['required', 'string', 'max:255']]);
        $shop = $this->normalizeShop($validated['shop_domain']);
        if (!$shop) {
            return response()->json(['error' => 'Enter a valid myshopify.com store domain.'], 422);
        }

        $clientId = config('services.shopify.client_id');
        $redirect = config('services.shopify.redirect');
        if (!$clientId || !$redirect || !config('services.shopify.client_secret')) {
            return response()->json(['error' => 'Shopify is not configured on the server.'], 503);
        }

        if (!config('services.shopify.use_legacy_install_flow')) {
            return response()->json([
                'error' => 'This connection starts from the NazBiz Channels page and requires Shopify standalone authorization-code flow. Set use_legacy_install_flow=true and embedded=false in the Shopify app settings, then deploy the app configuration.',
            ], 409);
        }

        $state = Str::random(48);
        Cache::put('shopify_oauth_state:' . hash('sha256', $state), [
            'user_id' => (int) $request->user()->id,
            'shop' => $shop,
            'created_at' => now()->timestamp,
        ], now()->addMinutes(10));

        $params = [
            'client_id' => $clientId,
            'redirect_uri' => $redirect,
            'response_type' => 'code',
            'state' => $state,
        ];
        $params['scope'] = config('services.shopify.scopes');

        return response()->json([
            'authorization_url' => 'https://' . $shop . '/admin/oauth/authorize?' . http_build_query($params),
        ]);
    }

    public function callback(Request $request)
    {
        $secret = config('services.shopify.client_secret');
        $shop = $this->normalizeShop((string) $request->query('shop', ''));
        if ($request->query('error')) {
            $state = (string) $request->query('state', '');
            $stateData = $state !== '' ? Cache::pull('shopify_oauth_state:' . hash('sha256', $state)) : null;
            if ($stateData && ($stateData['shop'] ?? null) === $shop) {
                return redirect($this->frontendUrl() . '/dashboard/channels?error=shopify_cancelled');
            }
        }
        if (!$secret || !$shop || !$this->validCallbackHmac($request, $secret)) {
            return redirect($this->frontendUrl() . '/dashboard/channels?error=shopify_invalid_callback');
        }

        $timestamp = filter_var($request->query('timestamp'), FILTER_VALIDATE_INT);
        if (!$timestamp || abs(now()->timestamp - $timestamp) > 600) {
            return redirect($this->frontendUrl() . '/dashboard/channels?error=shopify_expired_callback');
        }

        $state = (string) $request->query('state', '');
        $stateData = $state !== '' ? Cache::pull('shopify_oauth_state:' . hash('sha256', $state)) : null;
        if (!$stateData || ($stateData['shop'] ?? null) !== $shop || empty($stateData['user_id'])
            || now()->timestamp - (int) ($stateData['created_at'] ?? 0) > 600) {
            return redirect($this->frontendUrl() . '/dashboard/channels?error=shopify_invalid_state');
        }

        $code = $request->query('code');
        if (!$code || $request->query('error')) {
            return redirect($this->frontendUrl() . '/dashboard/channels?error=shopify_cancelled');
        }

        try {
            $tokenResponse = Http::asForm()->timeout(20)->post("https://{$shop}/admin/oauth/access_token", [
                'client_id' => config('services.shopify.client_id'),
                'client_secret' => $secret,
                'code' => $code,
            ]);
            if (!$tokenResponse->successful() || !$tokenResponse->json('access_token')) {
                Log::warning('Shopify OAuth token exchange failed', ['status' => $tokenResponse->status(), 'shop' => $shop]);
                return redirect($this->frontendUrl() . '/dashboard/channels?error=shopify_authorization_failed');
            }
            $token = $tokenResponse->json('access_token');
            $shopResponse = Http::withHeaders(['X-Shopify-Access-Token' => $token, 'Accept' => 'application/json'])
                ->timeout(20)->post("https://{$shop}/admin/api/" . config('services.shopify.api_version', '2026-07') . '/graphql.json', [
                    'query' => 'query { shop { id name myshopifyDomain } }',
                ]);
            $shopInfo = $shopResponse->json('data.shop');
            if (!$shopResponse->successful() || !$shopInfo || strtolower($shopInfo['myshopifyDomain'] ?? '') !== $shop) {
                Log::warning('Shopify shop verification failed', ['status' => $shopResponse->status(), 'shop' => $shop]);
                return redirect($this->frontendUrl() . '/dashboard/channels?error=shopify_verification_failed');
            }

            $business = \App\Models\BusinessProfile::firstOrCreate(['user_id' => $stateData['user_id']]);
            $channel = Channel::updateOrCreate(
                ['user_id' => $stateData['user_id'], 'type' => 'shopify', 'page_id' => $shop],
                [
                    'business_id' => $business->id,
                    'page_name' => $shopInfo['name'] ?? $shop,
                    'access_token' => $token,
                    'status' => 'connected',
                    'connected_at' => now(),
                    'metadata' => [
                        'shop_domain' => $shop,
                        'shop_gid' => $shopInfo['id'] ?? null,
                        'sync_status' => 'queued',
                        'sync_counts' => ['products' => 0, 'orders' => 0, 'customers' => 0],
                        'granted_scopes' => $tokenResponse->json('scope'),
                    ],
                ]
            );

            $this->registerWebhooks($channel);
            SyncCommerceStore::dispatch($channel->id);

            return redirect($this->frontendUrl() . '/dashboard/channels?success=shopify_connected');
        } catch (\Throwable $e) {
            Log::error('Shopify connection failed', ['shop' => $shop, 'error' => $e->getMessage()]);
            return redirect($this->frontendUrl() . '/dashboard/channels?error=shopify_connection_failed');
        }
    }

    public function sync(Request $request, int $channelId)
    {
        $channel = Channel::where('id', $channelId)->where('type', 'shopify')
            ->where('user_id', $request->user()->id)->where('status', 'connected')->firstOrFail();
        $metadata = $channel->metadata ?? [];
        $metadata['sync_status'] = 'queued';
        $channel->forceFill(['metadata' => $metadata])->save();
        SyncCommerceStore::dispatch($channel->id);
        return response()->json(['success' => true, 'status' => 'queued']);
    }

    public function webhook(Request $request)
    {
        $raw = $request->getContent();
        $provided = (string) $request->header('X-Shopify-Hmac-Sha256', '');
        $expected = base64_encode(hash_hmac('sha256', $raw, (string) config('services.shopify.client_secret'), true));
        if ($provided === '' || !hash_equals($expected, $provided)) {
            return response('Invalid signature', 401);
        }

        $shop = $this->normalizeShop((string) $request->header('X-Shopify-Shop-Domain', ''));
        $eventId = (string) $request->header('X-Shopify-Webhook-Id', '');
        $topic = (string) $request->header('X-Shopify-Topic', '');
        $channel = $shop ? Channel::where('type', 'shopify')->where('page_id', $shop)->where('status', 'connected')->first() : null;
        if (!$channel || $eventId === '') {
            return response('Unknown store or delivery', 404);
        }

        $inserted = DB::table('commerce_webhook_deliveries')->insertOrIgnore([
            'channel_id' => $channel->id, 'provider' => 'shopify', 'event_id' => $eventId,
            'topic' => $topic, 'processed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        if (!$inserted) {
            return response('OK', 200);
        }

        if ($topic === 'app/uninstalled') {
            $channel->delete();
            return response('OK', 200);
        }

        SyncCommerceStore::dispatch($channel->id);
        return response('OK', 200);
    }

    public function getOrders(Request $request)
    {
        $request->validate(['phone' => 'required|string|max:40', 'channel_id' => 'nullable|integer']);
        $query = Channel::where('type', 'shopify')->where('user_id', $request->user()->id)->where('status', 'connected');
        if ($request->filled('channel_id')) {
            $query->whereKey($request->integer('channel_id'));
        }
        $channels = $query->get();
        if ($channels->count() !== 1) {
            return response()->json(['error' => $channels->isEmpty() ? 'Shopify channel not connected' : 'Specify channel_id'], $channels->isEmpty() ? 404 : 409);
        }

        $orders = CommerceOrder::where('channel_id', $channels->first()->id)
            ->where('customer_phone', $request->query('phone'))->latest('ordered_at')->limit(1)->get();
        if ($orders->isEmpty()) {
            return response()->json(['error' => 'No order found for this phone'], 404);
        }
        return response()->json(['success' => true, 'order' => $orders->first()]);
    }

    private function registerWebhooks(Channel $channel): void
    {
        $domain = $channel->page_id;
        $version = config('services.shopify.api_version', '2026-07');
        $callback = rtrim((string) config('app.url'), '/') . '/api/shopify/webhook';
        $topics = [
            'PRODUCTS_CREATE', 'PRODUCTS_UPDATE', 'INVENTORY_LEVELS_UPDATE',
            'ORDERS_CREATE', 'ORDERS_UPDATED', 'ORDERS_CANCELLED',
            'FULFILLMENTS_CREATE', 'FULFILLMENTS_UPDATE', 'CUSTOMERS_CREATE', 'CUSTOMERS_UPDATE',
            'APP_UNINSTALLED',
        ];
        $registered = 0;
        $failures = [];

        // Reconnecting a shop must not fail just because Shopify already has
        // the same shop-scoped subscriptions from an earlier connection.
        try {
            $existingResponse = Http::withHeaders(['X-Shopify-Access-Token' => $channel->access_token])
                ->timeout(20)->post("https://{$domain}/admin/api/{$version}/graphql.json", [
                    'query' => 'query ExistingWebhooks { webhookSubscriptions(first: 250) { edges { node { id topic uri } } } }',
                ]);
            $existingPayload = $existingResponse->json();
            $existingEdges = $existingResponse->json('data.webhookSubscriptions.edges', []);
            if (!$existingResponse->successful() || !empty($existingPayload['errors']) || !is_array($existingEdges)) {
                Log::warning('Shopify existing webhook lookup failed; attempting registration anyway', [
                    'channel_id' => $channel->id,
                    'http_status' => $existingResponse->status(),
                    'errors' => $existingPayload['errors'] ?? [],
                ]);
                $existingEdges = [];
            }
        } catch (\Throwable $e) {
            $existingEdges = [];
            Log::warning('Shopify existing webhook lookup failed; attempting registration anyway', [
                'channel_id' => $channel->id,
                'error' => $e->getMessage(),
            ]);
        }

        $existing = [];
        foreach ($existingEdges as $edge) {
            $node = $edge['node'] ?? [];
            if (($node['uri'] ?? null) === $callback && isset($node['topic'])) {
                $existing[$node['topic']] = true;
            }
        }

        foreach ($topics as $topic) {
            if (isset($existing[$topic])) {
                $registered++;
                continue;
            }

            try {
                $response = Http::withHeaders(['X-Shopify-Access-Token' => $channel->access_token])
                    ->timeout(20)->post("https://{$domain}/admin/api/{$version}/graphql.json", [
                        'query' => 'mutation CreateWebhook($topic: WebhookSubscriptionTopic!, $webhookSubscription: WebhookSubscriptionInput!) { webhookSubscriptionCreate(topic: $topic, webhookSubscription: $webhookSubscription) { userErrors { field message } webhookSubscription { id uri } } }',
                        'variables' => ['topic' => $topic, 'webhookSubscription' => ['uri' => $callback, 'format' => 'JSON']],
                    ]);
                $subscription = $response->json('data.webhookSubscriptionCreate.webhookSubscription');
                if ($response->successful() && empty($response->json('errors'))
                    && empty($response->json('data.webhookSubscriptionCreate.userErrors'))
                    && !empty($subscription['id'])) {
                    $registered++;
                } else {
                    $reason = [
                        'http_status' => $response->status(),
                        'graphql_errors' => $response->json('errors', []),
                        'user_errors' => $response->json('data.webhookSubscriptionCreate.userErrors', []),
                    ];
                    $failures[$topic] = $reason;
                    Log::warning('Shopify webhook subscription was not created', [
                        'channel_id' => $channel->id,
                        'topic' => $topic,
                        ...$reason,
                    ]);
                }
            } catch (\Throwable $e) {
                $failures[$topic] = ['error' => $e->getMessage()];
                Log::warning('Shopify webhook subscription failed', [
                    'channel_id' => $channel->id,
                    'topic' => $topic,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $metadata = $channel->fresh()->metadata ?? [];
        $metadata['webhooks_registered'] = $registered;
        $metadata['webhook_failures'] = $failures;
        if ($failures) {
            $failedTopic = array_key_first($failures);
            $failure = $failures[$failedTopic];
            $details = $failure['user_errors'][0]['message']
                ?? $failure['graphql_errors'][0]['message']
                ?? $failure['error']
                ?? ('HTTP ' . ($failure['http_status'] ?? 'error'));
            $metadata['webhook_failure_message'] = $failedTopic . ': ' . Str::limit((string) $details, 240);
        } else {
            unset($metadata['webhook_failure_message']);
        }
        $metadata['webhook_status'] = $registered === count($topics)
            ? 'registered'
            : ($registered > 0 ? 'partial' : 'error');
        $channel->forceFill(['metadata' => $metadata])->save();
    }

    private function validCallbackHmac(Request $request, string $secret): bool
    {
        $params = $request->query();
        $given = (string) ($params['hmac'] ?? '');
        unset($params['hmac'], $params['signature']);
        if ($given === '') {
            return false;
        }
        ksort($params, SORT_STRING);
        $message = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        return hash_equals(hash_hmac('sha256', $message, $secret), $given);
    }

    private function normalizeShop(string $shop): ?string
    {
        $shop = strtolower(trim($shop));
        $shop = preg_replace('#^https?://#', '', $shop);
        $shop = rtrim($shop, '/');
        return preg_match('/\A[a-z0-9][a-z0-9-]*\.myshopify\.com\z/D', $shop) ? $shop : null;
    }

    private function frontendUrl(): string
    {
        return rtrim((string) config('app.frontend_url', env('FRONTEND_URL', '')), '/');
    }
}
