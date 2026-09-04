<?php

namespace App\Jobs;

use App\Models\Channel;
use App\Models\Conversation;
use App\Services\SallaService;
use App\Services\SequenceTriggerService;
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

        // Find or create conversation for the customer
        $customerPhone = $data['customer']['mobile'] ?? null;
        if ($customerPhone) {
            $conversation = Conversation::where('business_id', $channel->business_id)
                ->where('sender_id', $customerPhone)
                ->first();

            if (!$conversation) {
                // Create conversation for the order customer
                $conversation = Conversation::create([
                    'business_id' => $channel->business_id,
                    'channel_id' => $channel->id,
                    'sender_id' => $customerPhone,
                    'sender_name' => $data['customer']['name'] ?? null,
                    'sender_email' => $data['customer']['email'] ?? null,
                    'status' => 'active',
                ]);
            }

            // Trigger sequences with order-based triggers
            $sequenceTriggerService = new SequenceTriggerService();
            $sequenceTriggerService->checkAndEnrollForOrderCreated($conversation, $data);
        }

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
     * Handle app.installed event.
     *
     * ARCHITECTURE NOTE:
     * The webhook payload only contains merchant ID — it does NOT contain a Naz user ID.
     * Therefore we MUST NOT create a Channel here (user_id would be null → DB error).
     *
     * The OAuth callback (callbackSalla) is solely responsible for creating/claiming
     * the Channel and linking it to the correct Naz user.
     *
     * Here we only: update an ALREADY EXISTING channel for this merchant (e.g. re-install).
     */
    protected function handleAppInstalled(array $data): void
    {
        $merchantId = $data['merchant'] ?? null;
        $appId      = $data['id'] ?? null;
        $storeType  = $data['store_type'] ?? null;

        Log::info('Salla app.installed webhook received', [
            'merchant_id' => $merchantId,
            'app_id'      => $appId,
            'store_type'  => $storeType,
        ]);

        if (!$merchantId) {
            Log::error('Salla app.installed: missing merchant ID in payload', ['data_keys' => array_keys($data)]);
            return;
        }

        // Only update an EXISTING channel (created by OAuth). Never create a new one here.
        $channel = Channel::where('type', 'salla')
            ->where('page_id', (string) $merchantId)
            ->whereNotNull('user_id')
            ->where('user_id', '!=', 0)
            ->first();

        if ($channel) {
            $channel->update([
                'status'       => 'connected',
                'connected_at' => now(),
                'metadata'     => array_merge($channel->metadata ?? [], [
                    'merchant_id'  => $merchantId,
                    'app_id'       => $appId,
                    'store_type'   => $storeType,
                    'reinstalled_at' => now()->toISOString(),
                ]),
            ]);
            Log::info('Salla channel re-activated on app.installed', [
                'channel_id'  => $channel->id,
                'merchant_id' => $merchantId,
                'user_id'     => $channel->user_id,
            ]);
        } else {
            // No channel exists yet — merchant has not completed OAuth yet.
            // Do nothing. When they complete OAuth the channel will be created properly.
            Log::info('Salla app.installed: no OAuth channel found for merchant yet — awaiting OAuth', [
                'merchant_id' => $merchantId,
            ]);
        }
    }
}
