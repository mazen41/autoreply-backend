<?php

namespace App\Jobs;

use App\Models\Channel;
use App\Services\SallaService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SyncSallaOrders implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $backoff = 60;
    public $timeout = 300;

    public function __construct(
        public int $channelId,
        public ?int $page = null
    ) {
    }

    public function handle(): void
    {
        $channel = Channel::find($this->channelId);
        if (!$channel || $channel->type !== 'salla') {
            Log::error('Invalid Salla channel for order sync', ['channel_id' => $this->channelId]);
            return;
        }

        try {
            $sallaService = new SallaService();
            $params = [
                'per_page' => 50,
                'sort' => 'created_at',
            ];
            
            if ($this->page) {
                $params['page'] = $this->page;
            }

            $response = $sallaService->getCustomers($channel->access_token, $params);
            
            // We need to get orders differently - let me fix this
            // For now, let's get all orders directly
            $ordersResponse = Http::withToken($channel->access_token)
                ->accept('application/json')
                ->get('https://api.salla.dev/orders', $params);

            if (!$ordersResponse->successful()) {
                Log::error('Failed to fetch Salla orders', [
                    'status' => $ordersResponse->status(),
                    'body' => $ordersResponse->body(),
                ]);
                return;
            }

            $orders = $ordersResponse->json()['data'] ?? [];
            $pagination = $ordersResponse->json()['pagination'] ?? [];

            Log::info('Syncing Salla orders', [
                'channel_id' => $this->channelId,
                'count' => count($orders),
                'page' => $this->page ?? 1,
            ]);

            // Process each order
            foreach ($orders as $order) {
                // Store order data in channel metadata for AI access
                $metadata = $channel->metadata ?? [];
                if (!isset($metadata['orders'])) {
                    $metadata['orders'] = [];
                }
                
                // Update or add order
                $metadata['orders'][$order['id']] = [
                    'id' => $order['id'],
                    'reference_id' => $order['reference_id'] ?? null,
                    'status' => $order['status']['name'] ?? null,
                    'total' => $order['total']['amount'] ?? 0,
                    'currency' => $order['total']['currency'] ?? 'SAR',
                    'customer_mobile' => $order['customer']['mobile'] ?? null,
                    'customer_name' => $order['customer']['name'] ?? null,
                    'created_at' => $order['created_at'] ?? null,
                    'updated_at' => now()->toIso8601String(),
                ];
                
                $channel->metadata = $metadata;
                $channel->save();
            }

            // Check if there are more pages to sync
            if (isset($pagination['current_page']) && isset($pagination['total_pages'])) {
                if ($pagination['current_page'] < $pagination['total_pages']) {
                    // Dispatch next page
                    self::dispatch($this->channelId, $pagination['current_page'] + 1);
                }
            }

            Log::info('Salla orders sync completed', [
                'channel_id' => $this->channelId,
                'total_synced' => count($orders),
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to sync Salla orders', [
                'channel_id' => $this->channelId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }
}
