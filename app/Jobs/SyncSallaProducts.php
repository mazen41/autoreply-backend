<?php

namespace App\Jobs;

use App\Models\Channel;
use App\Models\Product;
use App\Services\SallaService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class SyncSallaProducts implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 1800;

    public function __construct(public int $channelId) {}

    public function handle(SallaService $salla): void
    {
        $channel = Channel::query()->whereKey($this->channelId)
            ->where('type', 'salla')->where('status', 'connected')->first();
        if (!$channel) return;

        $this->setStatus($channel, 'syncing');
        $count = 0;
        $page = 1;

        try {
            do {
                if ($page > 2000) {
                    throw new RuntimeException('Salla product sync exceeded pagination safety limit.');
                }

                $response = $salla->getProductsForChannel($channel, ['page' => $page, 'per_page' => 15]);
                $products = $response['data'] ?? null;
                if (!is_array($products)) {
                    throw new RuntimeException('Salla product sync returned an invalid page.');
                }

                foreach ($products as $product) {
                    if (!is_array($product) || !isset($product['id'])) continue;
                    $price = $product['price'] ?? null;
                    if (is_array($price)) $price = $price['amount'] ?? null;
                    if (!is_numeric($price)) {
                        continue;
                    }
                    $images = $product['images'] ?? [];
                    Product::updateOrCreate(
                        ['commerce_channel_id' => $channel->id, 'commerce_external_id' => (string) $product['id']],
                        [
                            'business_id' => $channel->business_id,
                            'name' => (string) ($product['name'] ?? 'Salla product'),
                            'description' => trim(strip_tags((string) ($product['description'] ?? ''))),
                            'price' => (float) $price,
                            'stock_quantity' => (int) ($product['quantity'] ?? 0),
                            'sku' => $product['sku'] ?? null,
                            'is_active' => !in_array(strtolower((string) ($product['status'] ?? 'active')), ['inactive', 'draft', 'deleted'], true),
                            'metadata' => [
                                'source' => 'salla',
                                'image_url' => $salla->extractImageUrl($product),
                                'images' => is_array($images) ? $images : [],
                                'url' => $product['urls']['customer'] ?? $product['url'] ?? null,
                                'currency' => is_array($product['price'] ?? null) ? ($product['price']['currency_code'] ?? null) : null,
                                'options' => $product['options'] ?? [],
                            ],
                        ]
                    );
                    $count++;
                }

                $pagination = $response['pagination'] ?? [];
                $totalPages = (int) ($pagination['totalPages'] ?? $pagination['total_pages'] ?? 0);
                $hasNext = $totalPages > 0
                    ? $page < $totalPages
                    : (!empty($pagination['links']['next']) || count($products) >= 15);
                $page++;
            } while ($hasNext);

            $this->setStatus($channel, 'completed', $count);
        } catch (\Throwable $exception) {
            $this->setStatus($channel, 'failed');
            Log::warning('Salla product catalog sync failed', [
                'channel_id' => $channel->id,
                'business_id' => $channel->business_id,
                'exception_type' => get_class($exception),
            ]);
            throw $exception;
        }
    }

    public function failed(?\Throwable $exception): void
    {
        $channel = Channel::query()->whereKey($this->channelId)->where('type', 'salla')->first();
        if ($channel) $this->setStatus($channel, 'failed');
    }

    private function setStatus(Channel $channel, string $status, ?int $count = null): void
    {
        $metadata = $channel->metadata ?? [];
        $metadata['sync_status'] = $status;
        unset($metadata['sync_error']);
        if ($count !== null) {
            $metadata['last_synced_at'] = now()->toIso8601String();
            $metadata['products_synced'] = $count;
        }
        if ($status === 'failed') {
            $metadata['sync_error'] = 'Catalog sync failed. Check the store connection and retry.';
        }
        $channel->forceFill(['metadata' => $metadata])->save();
    }
}
