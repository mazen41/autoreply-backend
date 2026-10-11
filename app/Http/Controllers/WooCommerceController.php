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
use App\Services\OAuthStateService;

class WooCommerceController extends Controller
{
    /** Start the WooCommerce REST API key authorization flow. */
    public function connect(Request $request)
    {
        $validated = $request->validate(['store_url' => ['required', 'url', 'max:255']]);
        $storeUrl = $this->normalizeStoreUrl($validated['store_url']);
        if (!$storeUrl) {
            return response()->json(['error' => 'Enter a public HTTPS WooCommerce store URL.'], 422);
        }

        $business = app(\App\Services\ActiveBusiness::class)->resolve($request, permission: 'write');
        $state = app(OAuthStateService::class)->issue('woocommerce', (int) $request->user()->id, [
            'store_url' => $storeUrl,
            'business_id' => $business->id,
        ]);
        $this->saveConnectionResult($state, (int) $request->user()->id, 'pending');

        $returnUrl = rtrim((string) config('app.frontend_url', env('FRONTEND_URL', '')), '/')
            . '/dashboard/channels?' . http_build_query([
                'connection' => 'woocommerce',
                'connection_state' => $state,
            ]);

        $params = [
            'app_name' => config('app.name', 'NazBiz'),
            'scope' => 'read_write',
            'user_id' => $state,
            'return_url' => $returnUrl,
            'callback_url' => rtrim((string) config('app.url'), '/') . '/api/channels/woocommerce/callback',
        ];

        return response()->json([
            'authorization_url' => $storeUrl . '/wc-auth/v1/authorize?' . http_build_query($params),
        ]);
    }

    /** Receives WooCommerce's server-to-server API key callback. */
    public function authorizationCallback(Request $request)
    {
        $payload = $request->json()->all();
        if (!$payload) {
            $payload = $request->all();
        }
        $state = (string) ($payload['user_id'] ?? '');
        $stateData = app(OAuthStateService::class)->consume('woocommerce', $state);
        if (!$stateData) {
            return response()->json(['error' => 'Authorization request expired or invalid.'], 401);
        }
        $userId = (int) $stateData['user_id'];
        $storeUrl = $stateData['context']['store_url'] ?? null;
        if (!$storeUrl || !\App\Models\User::whereKey($userId)->exists()) {
            return response()->json(['error' => 'Authorization request expired or invalid.'], 401);
        }
        try {
            $business = app(\App\Services\BusinessAccessService::class)->authorize(
                \App\Models\User::findOrFail($userId),
                (int) ($stateData['context']['business_id'] ?? 0),
                'write',
                hideUnauthorized: false,
            );
        } catch (\Throwable) {
            return response()->json(['error' => 'The selected business is no longer available.'], 403);
        }
        if (empty($payload['consumer_key']) || empty($payload['consumer_secret'])
            || ($payload['key_permissions'] ?? '') !== 'read_write') {
            $this->saveConnectionResult($state, $userId, 'failed', 'WooCommerce did not grant read/write API access.');
            return response()->json(['error' => 'WooCommerce did not grant the required read/write API access.'], 422);
        }

        $consumerKey = (string) $payload['consumer_key'];
        $consumerSecret = (string) $payload['consumer_secret'];
        try {
            $response = Http::withBasicAuth($consumerKey, $consumerSecret)->timeout(20)
                ->get($storeUrl . '/wp-json/wc/v3/system_status');
            if (!$response->successful()) {
                $this->saveConnectionResult($state, $userId, 'failed', 'WooCommerce could not verify the approved API key.');
                Log::warning('WooCommerce authorization credentials failed verification', [
                    'status' => $response->status(), 'store_url' => $storeUrl,
                ]);
                return response()->json(['error' => 'WooCommerce could not verify the approved API key.'], 422);
            }

            $status = $response->json();
            $channel = Channel::updateOrCreate(
                ['user_id' => $userId, 'type' => 'woocommerce', 'page_id' => $storeUrl],
                [
                    'business_id' => $business->id,
                    'page_name' => $status['settings']['store_name'] ?? parse_url($storeUrl, PHP_URL_HOST),
                    'access_token' => '',
                    'status' => 'connected',
                    'connected_at' => now(),
                    'metadata' => [
                        'store_url' => $storeUrl,
                        'consumer_key' => encrypt($consumerKey),
                        'consumer_secret' => encrypt($consumerSecret),
                        'webhook_secret' => encrypt(Str::random(64)),
                        'sync_status' => 'queued',
                        'sync_counts' => ['products' => 0, 'orders' => 0, 'customers' => 0],
                    ],
                ]
            );

            $this->registerWebhooks($channel);
            SyncCommerceStore::dispatch($channel->id);
            $this->saveConnectionResult($state, $userId, 'connected', null, $channel->id);
            return response()->json(['success' => true]);
        } catch (\Throwable $e) {
            $this->saveConnectionResult($state, $userId, 'failed', 'Could not finish connecting WooCommerce. Please try again.');
            Log::error('WooCommerce authorization callback failed', [
                'store_url' => $storeUrl, 'exception_type' => get_class($e),
            ]);
            return response()->json(['error' => 'Could not finish connecting WooCommerce.'], 500);
        }
    }

    /** Return the server-verified result after WooCommerce redirects back. */
    public function connectionStatus(Request $request)
    {
        $validated = $request->validate(['state' => ['required', 'string', 'max:100']]);
        $result = Cache::get($this->connectionResultKey($validated['state']));
        if (!$result || (int) ($result['user_id'] ?? 0) !== (int) $request->user()->id) {
            return response()->json(['status' => 'expired', 'error' => 'Connection request expired. Please try again.'], 404);
        }

        return response()->json(array_filter([
            'status' => $result['status'] ?? 'pending',
            'channel_id' => $result['channel_id'] ?? null,
            'error' => $result['error'] ?? null,
        ], fn ($value) => $value !== null));
    }

    public function sync(Request $request, int $channelId)
    {
        $channel = Channel::where('id', $channelId)->where('type', 'woocommerce')
            ->where('user_id', $request->user()->id)->where('status', 'connected')->firstOrFail();
        $metadata = $channel->metadata ?? [];
        $metadata['sync_status'] = 'queued';
        $channel->forceFill(['metadata' => $metadata])->save();
        SyncCommerceStore::dispatch($channel->id);
        return response()->json(['success' => true, 'status' => 'queued']);
    }

    public function getOrders(Request $request)
    {
        $request->validate(['phone' => 'required|string|max:40', 'channel_id' => 'nullable|integer']);
        $query = Channel::where('type', 'woocommerce')->where('user_id', $request->user()->id)->where('status', 'connected');
        if ($request->filled('channel_id')) {
            $query->whereKey($request->integer('channel_id'));
        }
        $channels = $query->get();
        if ($channels->count() !== 1) {
            return response()->json(['error' => $channels->isEmpty() ? 'WooCommerce channel not connected' : 'Specify channel_id'], $channels->isEmpty() ? 404 : 409);
        }
        $order = CommerceOrder::where('channel_id', $channels->first()->id)
            ->where('customer_phone', $request->query('phone'))->latest('ordered_at')->first();
        return $order ? response()->json(['success' => true, 'order' => $order]) : response()->json(['order' => null]);
    }

    public function webhook(Request $request)
    {
        $raw = $request->getContent();
        $storeUrl = $this->normalizeStoreUrl((string) $request->header('X-WC-Webhook-Source', ''));
        $deliveryId = (string) $request->header('X-WC-Webhook-ID', '');
        $topic = (string) $request->header('X-WC-Webhook-Topic', '');
        $channel = $storeUrl ? Channel::where('type', 'woocommerce')->where('page_id', $storeUrl)->where('status', 'connected')->first() : null;
        if (!$channel || $deliveryId === '') {
            return response('Unknown store or delivery', 404);
        }

        $metadata = $channel->metadata ?? [];
        try {
            $webhookSecret = decrypt($metadata['webhook_secret'] ?? '');
        } catch (\Throwable) {
            return response('Invalid webhook configuration', 401);
        }
        $signature = base64_encode(hash_hmac('sha256', $raw, $webhookSecret, true));
        if (!hash_equals($signature, (string) $request->header('X-WC-Webhook-Signature', ''))) {
            return response('Invalid signature', 401);
        }

        $inserted = DB::table('commerce_webhook_deliveries')->insertOrIgnore([
            'channel_id' => $channel->id, 'provider' => 'woocommerce', 'event_id' => $deliveryId,
            'topic' => $topic, 'processed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($inserted) {
            SyncCommerceStore::dispatch($channel->id);
        }
        return response('OK', 200);
    }

    private function registerWebhooks(Channel $channel): void
    {
        $metadata = $channel->metadata ?? [];
        $storeUrl = rtrim($metadata['store_url'] ?? $channel->page_id, '/');
        $consumerKey = decrypt($metadata['consumer_key'] ?? '');
        $consumerSecret = decrypt($metadata['consumer_secret'] ?? '');
        $webhookSecret = decrypt($metadata['webhook_secret'] ?? '');
        $deliveryUrl = rtrim((string) config('app.url'), '/') . '/api/woocommerce/webhook';
        $topics = ['order.created', 'order.updated', 'product.created', 'product.updated', 'customer.created', 'customer.updated'];
        $registered = 0;
        foreach ($topics as $topic) {
            try {
                $response = Http::withBasicAuth($consumerKey, $consumerSecret)->timeout(20)
                    ->post($storeUrl . '/wp-json/wc/v3/webhooks', [
                        'name' => 'NazBiz ' . $topic,
                        'topic' => $topic,
                        'delivery_url' => $deliveryUrl,
                        'secret' => $webhookSecret,
                        'status' => 'active',
                    ]);
                if ($response->successful() && !empty($response->json('id'))) {
                    $registered++;
                } else {
                    Log::warning('WooCommerce webhook registration failed', [
                        'channel_id' => $channel->id, 'topic' => $topic, 'status' => $response->status(),
                    ]);
                }
            } catch (\Throwable $e) {
                Log::warning('WooCommerce webhook registration error', [
                    'channel_id' => $channel->id, 'topic' => $topic, 'exception_type' => get_class($e),
                ]);
            }
        }

        $metadata = $channel->fresh()->metadata ?? [];
        $metadata['webhooks_registered'] = $registered;
        $metadata['webhook_status'] = $registered === count($topics)
            ? 'registered'
            : ($registered > 0 ? 'partial' : 'error');
        $channel->forceFill(['metadata' => $metadata])->save();
    }

    private function normalizeStoreUrl(string $value): ?string
    {
        $parts = parse_url(trim($value));
        if (!$parts || strtolower($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return null;
        }
        $host = strtolower($parts['host']);
        if (!filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)
            || in_array($host, ['localhost', 'localhost.localdomain'], true)
            || filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false
                && filter_var($host, FILTER_VALIDATE_IP)) {
            return null;
        }
        $path = rtrim($parts['path'] ?? '', '/');
        return 'https://' . $host . (isset($parts['port']) ? ':' . $parts['port'] : '') . $path;
    }

    private function connectionResultKey(string $state): string
    {
        return 'woocommerce_connection_result:' . hash('sha256', $state);
    }

    private function saveConnectionResult(string $state, int $userId, string $status, ?string $error = null, ?int $channelId = null): void
    {
        Cache::put($this->connectionResultKey($state), array_filter([
            'user_id' => $userId,
            'status' => $status,
            'error' => $error,
            'channel_id' => $channelId,
        ], fn ($value) => $value !== null), now()->addMinutes(20));
    }
}
