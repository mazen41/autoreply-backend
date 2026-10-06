<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\CommerceOrder;
use App\Models\Customer;
use App\Models\Product;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class CommerceStoreSyncService
{
    public function sync(Channel $channel): array
    {
        if (!in_array($channel->type, ['shopify', 'woocommerce'], true)) {
            throw new RuntimeException('Unsupported commerce provider.');
        }

        $channel->forceFill(['metadata' => array_merge($channel->metadata ?? [], [
            'sync_status' => 'syncing',
            'sync_started_at' => now()->toIso8601String(),
            'sync_error' => null,
            'sync_warnings' => [],
        ])])->save();

        try {
            $warnings = [];
            $counts = $channel->type === 'shopify'
                ? $this->syncShopify($channel, $warnings)
                : $this->syncWooCommerce($channel);

            $metadata = $channel->fresh()->metadata ?? [];
            $metadata['sync_status'] = 'connected';
            $metadata['last_synced_at'] = now()->toIso8601String();
            $metadata['sync_counts'] = $counts;
            $metadata['sync_error'] = null;
            $metadata['sync_warnings'] = $warnings;
            $channel->forceFill(['metadata' => $metadata])->save();

            return $counts;
        } catch (\Throwable $e) {
            $metadata = $channel->fresh()->metadata ?? [];
            $metadata['sync_status'] = 'error';
            $metadata['sync_error'] = $e->getMessage();
            $channel->forceFill(['metadata' => $metadata])->save();
            throw $e;
        }
    }

    private function syncShopify(Channel $channel, array &$warnings): array
    {
        $counts = ['products' => 0, 'orders' => 0, 'customers' => 0];
        $domain = strtolower($channel->page_id);
        $version = config('services.shopify.api_version', '2026-07');
        $headers = ['X-Shopify-Access-Token' => $channel->access_token, 'Accept' => 'application/json'];

        $this->paginateShopify($domain, $version, $headers, <<<'GQL'
query Products($after: String) {
  products(first: 100, after: $after) {
    edges { cursor node {
      id title descriptionHtml handle status vendor productType tags onlineStoreUrl
      featuredImage { url altText }
      images(first: 20) { edges { node { url altText } } }
      variants(first: 100) { edges { node { id title sku price inventoryQuantity availableForSale } } }
    } }
    pageInfo { hasNextPage endCursor }
  }
}
GQL, 'products', function (array $product) use ($channel, &$counts): void {
            $variants = array_map(fn ($edge) => $edge['node'] ?? [], $product['variants']['edges'] ?? []);
            $first = $variants[0] ?? [];
            $images = array_map(fn ($edge) => $edge['node'] ?? [], $product['images']['edges'] ?? []);
            $featured = $product['featuredImage']['url'] ?? ($images[0]['url'] ?? null);
            $inventoryQuantities = array_filter(array_map(fn ($variant) => $variant['inventoryQuantity'] ?? null, $variants), fn ($quantity) => $quantity !== null);
            $stock = $inventoryQuantities
                ? array_sum(array_map('intval', $inventoryQuantities))
                : (count(array_filter($variants, fn ($variant) => $variant['availableForSale'] ?? false)) > 0 ? 1 : 0);
            $variantText = implode('; ', array_filter(array_map(function ($variant) {
                $parts = array_filter([$variant['title'] ?? null, $variant['sku'] ?? null, $variant['price'] ?? null]);
                return $parts ? implode(' / ', $parts) : null;
            }, $variants)));

            Product::updateOrCreate(
                ['commerce_channel_id' => $channel->id, 'commerce_external_id' => (string) $product['id']],
                [
                    'business_id' => $channel->business_id,
                    'name' => $product['title'] ?? 'Shopify product',
                    'description' => trim(strip_tags($product['descriptionHtml'] ?? '')) . ($variantText ? "\nVariants: {$variantText}" : ''),
                    'price' => (float) ($first['price'] ?? 0),
                    'stock_quantity' => $stock,
                    'sku' => $first['sku'] ?? null,
                    'is_active' => ($product['status'] ?? '') === 'ACTIVE',
                    'metadata' => [
                        'source' => 'shopify', 'handle' => $product['handle'] ?? null,
                        'vendor' => $product['vendor'] ?? null, 'product_type' => $product['productType'] ?? null,
                        'tags' => $product['tags'] ?? [], 'url' => $product['onlineStoreUrl'] ?? null,
                        'image_url' => $featured, 'images' => $images, 'variants' => $variants,
                    ],
                ]
            );
            $counts['products']++;
        });

        $this->paginateShopify($domain, $version, $headers, <<<'GQL'
query Orders($after: String) {
  orders(first: 100, after: $after, sortKey: CREATED_AT) {
    edges { cursor node {
      id name createdAt updatedAt displayFinancialStatus displayFulfillmentStatus cancelledAt
      currencyCode totalPriceSet { shopMoney { amount currencyCode } }
      lineItems(first: 100) { edges { node { title quantity sku originalUnitPriceSet { shopMoney { amount currencyCode } } variant { id product { id } } } } }
    } }
    pageInfo { hasNextPage endCursor }
  }
}
GQL, 'orders', function (array $order) use ($channel, &$counts): void {
            $money = $order['totalPriceSet']['shopMoney'] ?? [];
            $lineItems = array_map(fn ($edge) => $edge['node'] ?? [], $order['lineItems']['edges'] ?? []);

            CommerceOrder::updateOrCreate(
                ['channel_id' => $channel->id, 'external_id' => (string) $order['id']],
                [
                    'business_id' => $channel->business_id, 'order_number' => $order['name'] ?? null,
                    'status' => strtolower($order['displayFinancialStatus'] ?? 'unknown'),
                    'fulfillment_status' => strtolower($order['displayFulfillmentStatus'] ?? 'unfulfilled'),
                    'total' => (float) ($money['amount'] ?? 0), 'currency' => $money['currencyCode'] ?? null,
                    'customer_name' => null, 'customer_email' => null, 'customer_phone' => null,
                    'shipping_address' => null, 'line_items' => $lineItems,
                    'raw_data' => ['id' => $order['id'], 'updated_at' => $order['updatedAt'] ?? null, 'cancelled_at' => $order['cancelledAt'] ?? null],
                    'ordered_at' => $order['createdAt'] ?? null,
                ]
            );
            $counts['orders']++;
        });

        try {
            $this->paginateShopify($domain, $version, $headers, <<<'GQL'
query Customers($after: String) {
  customers(first: 100, after: $after, sortKey: CREATED_AT) {
    edges { cursor node { id displayName firstName lastName email phone state createdAt updatedAt } }
    pageInfo { hasNextPage endCursor }
  }
}
GQL, 'customers', function (array $remote) use ($channel, &$counts): void {
            Customer::updateOrCreate(
                ['commerce_channel_id' => $channel->id, 'commerce_external_id' => (string) $remote['id']],
                [
                    'business_profile_id' => $channel->business_id,
                    'name' => $remote['displayName'] ?? trim(($remote['firstName'] ?? '') . ' ' . ($remote['lastName'] ?? '')),
                    'email' => $remote['email'] ?? null, 'phone' => $remote['phone'] ?? null,
                    'custom_fields' => ['commerce_provider' => 'shopify', 'state' => $remote['state'] ?? null,
                        'created_at' => $remote['createdAt'] ?? null, 'updated_at' => $remote['updatedAt'] ?? null],
                ]
            );
            $counts['customers']++;
            });
        } catch (RuntimeException $e) {
            if (!str_contains(strtolower($e->getMessage()), 'not approved to access the customer object')
                && !str_contains(strtolower($e->getMessage()), 'protected customer data')) {
                throw $e;
            }

            $warnings[] = 'Shopify customer sync is paused until Protected Customer Data access is approved.';
            Log::warning('Shopify customer sync skipped because protected customer data is not approved', [
                'channel_id' => $channel->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $counts;
    }

    private function paginateShopify(string $domain, string $version, array $headers, string $query, string $resource, callable $consume): void
    {
        $after = null;
        $pages = 0;
        do {
            if (++$pages > 1000) {
                throw new RuntimeException("Shopify {$resource} sync exceeded its pagination safety limit.");
            }
            $response = Http::withHeaders($headers)->timeout(45)
                ->post("https://{$domain}/admin/api/{$version}/graphql.json", ['query' => $query, 'variables' => ['after' => $after]]);
            if (!$response->successful()) {
                throw new RuntimeException("Shopify {$resource} sync failed (HTTP {$response->status()}).");
            }
            $body = $response->json();
            if (!empty($body['errors'])) {
                throw new RuntimeException('Shopify GraphQL rejected the sync query: ' . ($body['errors'][0]['message'] ?? 'unknown error'));
            }
            $connection = $body['data'][$resource] ?? null;
            if (!$connection) {
                throw new RuntimeException("Shopify {$resource} sync returned no connection data.");
            }
            foreach ($connection['edges'] ?? [] as $edge) {
                if (isset($edge['node']) && is_array($edge['node'])) {
                    $consume($edge['node']);
                }
            }
            $pageInfo = $connection['pageInfo'] ?? [];
            $after = $pageInfo['endCursor'] ?? null;
            $hasNext = (bool) ($pageInfo['hasNextPage'] ?? false);
            if ($hasNext && !$after) {
                throw new RuntimeException("Shopify {$resource} sync returned an invalid pagination cursor.");
            }
        } while ($hasNext);
    }

    private function syncWooCommerce(Channel $channel): array
    {
        $metadata = $channel->metadata ?? [];
        $storeUrl = rtrim($metadata['store_url'] ?? $channel->page_id, '/');
        $consumerKey = decrypt($metadata['consumer_key'] ?? '');
        $consumerSecret = decrypt($metadata['consumer_secret'] ?? '');
        $counts = ['products' => 0, 'orders' => 0, 'customers' => 0];

        $this->paginateWoo($storeUrl, $consumerKey, $consumerSecret, 'products', function (array $product) use ($channel, &$counts): void {
            $images = $product['images'] ?? [];
            $variants = $product['variations'] ?? [];
            Product::updateOrCreate(
                ['commerce_channel_id' => $channel->id, 'commerce_external_id' => (string) $product['id']],
                [
                    'business_id' => $channel->business_id,
                    'name' => $product['name'] ?? 'WooCommerce product',
                    'description' => trim(strip_tags($product['short_description'] ?? $product['description'] ?? '')),
                    'price' => (float) ($product['price'] ?? 0),
                    'stock_quantity' => (int) ($product['stock_quantity'] ?? (($product['stock_status'] ?? '') === 'instock' ? 1 : 0)),
                    'sku' => $product['sku'] ?? null,
                    'is_active' => ($product['status'] ?? '') === 'publish' && ($product['stock_status'] ?? 'instock') !== 'outofstock',
                    'metadata' => ['source' => 'woocommerce', 'url' => $product['permalink'] ?? null,
                        'image_url' => $images[0]['src'] ?? null, 'images' => $images,
                        'categories' => $product['categories'] ?? [], 'attributes' => $product['attributes'] ?? [],
                        'variations' => $variants, 'type' => $product['type'] ?? null],
                ]
            );
            $counts['products']++;
        });

        $this->paginateWoo($storeUrl, $consumerKey, $consumerSecret, 'orders', function (array $order) use ($channel, &$counts): void {
            $billing = $order['billing'] ?? [];
            $shipping = $order['shipping'] ?? [];
            $address = implode(', ', array_filter([
                $shipping['address_1'] ?? null, $shipping['address_2'] ?? null, $shipping['city'] ?? null,
                $shipping['state'] ?? null, $shipping['country'] ?? null, $shipping['postcode'] ?? null,
            ]));
            CommerceOrder::updateOrCreate(
                ['channel_id' => $channel->id, 'external_id' => (string) $order['id']],
                [
                    'business_id' => $channel->business_id, 'order_number' => $order['number'] ?? null,
                    'status' => $order['status'] ?? null, 'fulfillment_status' => $order['shipping_lines'][0]['method_title'] ?? null,
                    'total' => (float) ($order['total'] ?? 0), 'currency' => $order['currency'] ?? null,
                    'customer_name' => trim(($billing['first_name'] ?? '') . ' ' . ($billing['last_name'] ?? '')),
                    'customer_email' => $billing['email'] ?? null, 'customer_phone' => $billing['phone'] ?? null,
                    'shipping_address' => $address ?: null, 'line_items' => $order['line_items'] ?? [],
                    'raw_data' => ['date_modified' => $order['date_modified'] ?? null], 'ordered_at' => $order['date_created'] ?? null,
                ]
            );
            $counts['orders']++;
        });

        $this->paginateWoo($storeUrl, $consumerKey, $consumerSecret, 'customers', function (array $remote) use ($channel, &$counts): void {
            Customer::updateOrCreate(
                ['commerce_channel_id' => $channel->id, 'commerce_external_id' => (string) $remote['id']],
                [
                    'business_profile_id' => $channel->business_id,
                    'name' => trim(($remote['first_name'] ?? '') . ' ' . ($remote['last_name'] ?? '')),
                    'email' => $remote['email'] ?? null, 'phone' => $remote['billing']['phone'] ?? null,
                    'custom_fields' => ['commerce_provider' => 'woocommerce', 'username' => $remote['username'] ?? null,
                        'date_created' => $remote['date_created'] ?? null],
                ]
            );
            $counts['customers']++;
        });

        return $counts;
    }

    private function paginateWoo(string $storeUrl, string $consumerKey, string $consumerSecret, string $resource, callable $consume): void
    {
        for ($page = 1; $page <= 1000; $page++) {
            $response = Http::withBasicAuth($consumerKey, $consumerSecret)->timeout(45)
                ->get("{$storeUrl}/wp-json/wc/v3/{$resource}", ['per_page' => 100, 'page' => $page]);
            if (!$response->successful()) {
                throw new RuntimeException("WooCommerce {$resource} sync failed (HTTP {$response->status()}).");
            }
            $records = $response->json();
            if (!is_array($records)) {
                throw new RuntimeException("WooCommerce {$resource} sync returned an invalid response.");
            }
            foreach ($records as $record) {
                if (is_array($record) && isset($record['id'])) {
                    $consume($record);
                }
            }
            if (count($records) < 100) {
                return;
            }
        }
        throw new RuntimeException("WooCommerce {$resource} sync exceeded its pagination safety limit.");
    }
}
