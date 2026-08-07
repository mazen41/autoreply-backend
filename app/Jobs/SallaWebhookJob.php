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

class SallaWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $backoff = 30;

    public function __construct(
        public string $event,
        public array $data
    ) {
    }

    public function handle(): void
    {
        Log::info('Processing Salla webhook event', [
            'event' => $this->event,
            'data_keys' => array_keys($this->data),
        ]);

        // Handle app.installed event (Salla Easy Mode)
        if ($this->event === 'app.installed') {
            $this->handleAppInstalled($this->data);
            return;
        }

        // Find the Salla channel by store ID
        $storeId = $this->data['store_id'] ?? null;
        if (!$storeId) {
            Log::error('No store_id in webhook data');
            return;
        }

        $channel = Channel::where('type', 'salla')
            ->where('store_id', $storeId)
            ->first();

        if (!$channel) {
            Log::error('Salla channel not found for store', ['store_id' => $storeId]);
            return;
        }

        // Handle different event types
        switch ($this->event) {
            case 'order.created':
                $this->handleOrderCreated($channel, $this->data);
                break;
            case 'order.status.updated':
                $this->handleOrderStatusUpdated($channel, $this->data);
                break;
            case 'order.shipment.created':
                $this->handleOrderShipmentCreated($channel, $this->data);
                break;
            case 'order.canceled':
                $this->handleOrderCanceled($channel, $this->data);
                break;
            case 'order.refunded':
                $this->handleOrderRefunded($channel, $this->data);
                break;
            case 'order.customer.updated':
                $this->handleOrderCustomerUpdated($channel, $this->data);
                break;
            case 'order.shipping.address.updated':
                $this->handleOrderShippingAddressUpdated($channel, $this->data);
                break;
            case 'order.products.updated':
                $this->handleOrderProductsUpdated($channel, $this->data);
                break;
            case 'customer.created':
                $this->handleCustomerCreated($channel, $this->data);
                break;
            case 'customer.updated':
                $this->handleCustomerUpdated($channel, $this->data);
                break;
            case 'shipment.created':
                $this->handleShipmentCreated($channel, $this->data);
                break;
            case 'shipment.updated':
                $this->handleShipmentUpdated($channel, $this->data);
                break;
            case 'shipment.cancelled':
                $this->handleShipmentCancelled($channel, $this->data);
                break;
            default:
                Log::info('Unhandled Salla webhook event', ['event' => $this->event]);
        }
    }

    protected function handleOrderCreated(Channel $channel, array $data): void
    {
        Log::info('Salla order created', [
            'order_id' => $data['id'] ?? null,
            'reference_id' => $data['reference_id'] ?? null,
        ]);

        // Store order data in channel metadata for AI access
        $metadata = $channel->metadata ?? [];
        $metadata['last_order'] = $data;
        $channel->metadata = $metadata;
        $channel->save();

        // Trigger sync job for this order
        // This could be used to sync order details to local database
    }

    protected function handleOrderStatusUpdated(Channel $channel, array $data): void
    {
        Log::info('Salla order status updated', [
            'order_id' => $data['id'] ?? null,
            'new_status' => $data['status']['name'] ?? null,
        ]);

        // Update cached order data
        $metadata = $channel->metadata ?? [];
        if (isset($metadata['last_order']) && $metadata['last_order']['id'] === $data['id']) {
            $metadata['last_order'] = $data;
            $channel->metadata = $metadata;
            $channel->save();
        }
    }

    protected function handleOrderShipmentCreated(Channel $channel, array $data): void
    {
        Log::info('Salla order shipment created', [
            'order_id' => $data['id'] ?? null,
        ]);
    }

    protected function handleOrderCanceled(Channel $channel, array $data): void
    {
        Log::info('Salla order canceled', [
            'order_id' => $data['id'] ?? null,
        ]);
    }

    protected function handleOrderRefunded(Channel $channel, array $data): void
    {
        Log::info('Salla order refunded', [
            'order_id' => $data['id'] ?? null,
        ]);
    }

    protected function handleOrderCustomerUpdated(Channel $channel, array $data): void
    {
        Log::info('Salla order customer updated', [
            'order_id' => $data['id'] ?? null,
        ]);
    }

    protected function handleOrderShippingAddressUpdated(Channel $channel, array $data): void
    {
        Log::info('Salla order shipping address updated', [
            'order_id' => $data['id'] ?? null,
        ]);
    }

    protected function handleOrderProductsUpdated(Channel $channel, array $data): void
    {
        Log::info('Salla order products updated', [
            'order_id' => $data['id'] ?? null,
        ]);
    }

    protected function handleCustomerCreated(Channel $channel, array $data): void
    {
        Log::info('Salla customer created', [
            'customer_id' => $data['id'] ?? null,
            'phone' => $data['mobile'] ?? null,
        ]);

        // Trigger customer sync job
        // SyncCustomerJob::dispatch($channel->id, $data['id']);
    }

    protected function handleCustomerUpdated(Channel $channel, array $data): void
    {
        Log::info('Salla customer updated', [
            'customer_id' => $data['id'] ?? null,
        ]);
    }

    protected function handleShipmentCreated(Channel $channel, array $data): void
    {
        Log::info('Salla shipment created', [
            'shipment_id' => $data['id'] ?? null,
        ]);
    }

    protected function handleShipmentUpdated(Channel $channel, array $data): void
    {
        Log::info('Salla shipment updated', [
            'shipment_id' => $data['id'] ?? null,
        ]);
    }

    protected function handleShipmentCancelled(Channel $channel, array $data): void
    {
        Log::info('Salla shipment cancelled', [
            'shipment_id' => $data['id'] ?? null,
        ]);
    }

    /**
     * Handle app.installed event (Salla Easy Mode)
     */
    protected function handleAppInstalled(array $data): void
    {
        $merchantId = $data['merchant'] ?? null;
        $appId      = $data['id'] ?? null;
        $storeType  = $data['store_type'] ?? null;
        $storeName  = $data['app_name'] ?? 'Salla Store';

        Log::info('Salla app installed', [
            'merchant_id' => $merchantId,
            'app_id'      => $appId,
            'store_type'  => $storeType,
        ]);

        if (!$merchantId) {
            Log::error('Salla app.installed: missing merchant ID in payload', ['data_keys' => array_keys($data)]);
            return;
        }

        // Look for an existing Salla channel that matched this merchant.
        // Channels connected via OAuth store the merchant/store id in page_id.
        $channel = Channel::where('type', 'salla')
            ->where('page_id', (string) $merchantId)
            ->first();

        if ($channel) {
            // Mark as active in case it was previously disconnected.
            $channel->update([
                'status'      => 'connected',
                'connected_at' => now(),
                'metadata'    => array_merge($channel->metadata ?? [], [
                    'merchant_id' => $merchantId,
                    'app_id'      => $appId,
                    'store_type'  => $storeType,
                    'installed_at' => now()->toISOString(),
                ]),
            ]);
            Log::info('Salla channel re-activated for merchant', [
                'channel_id'  => $channel->id,
                'merchant_id' => $merchantId,
            ]);
        } else {
            // No channel yet — create a placeholder so the merchant appears
            // in the system. A proper OAuth connection will overwrite this.
            $channel = Channel::create([
                'user_id'     => null, // unknown until OAuth completes
                'type'        => 'salla',
                'page_id'     => (string) $merchantId,
                'page_name'   => $storeName,
                'status'      => 'pending_oauth',
                'connected_at' => now(),
                'metadata'    => [
                    'merchant_id'  => $merchantId,
                    'app_id'       => $appId,
                    'store_type'   => $storeType,
                    'installed_at' => now()->toISOString(),
                ],
            ]);
            Log::info('Salla placeholder channel created for new merchant', [
                'channel_id'  => $channel->id,
                'merchant_id' => $merchantId,
            ]);
        }
    }
}
