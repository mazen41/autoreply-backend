<?php

namespace App\Jobs;

use App\Models\Channel;
use App\Services\SallaService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncSallaCustomers implements ShouldQueue
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
            Log::error('Invalid Salla channel for customer sync', ['channel_id' => $this->channelId]);
            return;
        }

        try {
            $sallaService = new SallaService();
            $params = ['per_page' => 50];
            
            if ($this->page) {
                $params['page'] = $this->page;
            }

            $response = $sallaService->getCustomers($channel->access_token, $params);
            $customers = $response['data'] ?? [];
            $pagination = $response['pagination'] ?? [];

            Log::info('Syncing Salla customers', [
                'channel_id' => $this->channelId,
                'count' => count($customers),
                'page' => $this->page ?? 1,
            ]);

            // Process each customer
            foreach ($customers as $customer) {
                // Store customer data in channel metadata or separate table
                // For now, we'll store in metadata for AI access
                $metadata = $channel->metadata ?? [];
                if (!isset($metadata['customers'])) {
                    $metadata['customers'] = [];
                }
                
                // Update or add customer
                $metadata['customers'][$customer['id']] = [
                    'id' => $customer['id'],
                    'name' => $customer['name'] ?? null,
                    'email' => $customer['email'] ?? null,
                    'mobile' => $customer['mobile'] ?? null,
                    'city' => $customer['city']['name'] ?? null,
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

            Log::info('Salla customers sync completed', [
                'channel_id' => $this->channelId,
                'total_synced' => count($customers),
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to sync Salla customers', [
                'channel_id' => $this->channelId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }
}
