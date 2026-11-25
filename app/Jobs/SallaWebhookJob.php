<?php

namespace App\Jobs;

use App\Models\Channel;
use App\Models\CustomerIdentity;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\SallaWebhookReceipt;
use App\Services\SequenceTriggerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

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
        $eventIdentity = [
            'event' => $this->event,
            'merchant' => $this->data['merchant'] ?? $this->data['store_id'] ?? null,
            'id' => $this->data['id'] ?? null,
            'created_at' => $this->data['created_at'] ?? $this->data['date']['date'] ?? null,
            'updated_at' => $this->event === 'order.status.updated' ? ($this->data['updated_at'] ?? null) : null,
            'status' => $this->event === 'order.status.updated' ? ($this->data['status']['id'] ?? $this->data['status']['name'] ?? null) : null,
        ];
        if ($eventIdentity['id'] === null) {
            $eventIdentity['data_hash'] = hash('sha256', json_encode($this->data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
        }
        $eventKey = hash('sha256', json_encode($eventIdentity, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
        $lock = Cache::lock('salla-webhook:' . $eventKey, 120);
        if (!$lock->get()) {
            throw new \RuntimeException('Salla webhook event is already being processed.');
        }

        $receipt = null;
        try {
            $receipt = SallaWebhookReceipt::query()->firstOrCreate(
                ['event_key' => $eventKey],
                ['event' => $this->event, 'status' => 'processing', 'attempts' => 1]
            );
            if (!$receipt->wasRecentlyCreated && $receipt->status === 'processed') {
                Log::info('Skipping duplicate Salla webhook delivery', ['event' => $this->event]);
                return;
            }
            $receipt->update([
                'status' => 'processing',
                'attempts' => (int) $receipt->attempts + ($receipt->wasRecentlyCreated ? 0 : 1),
            ]);

            $this->processEvent();
            $receipt->update(['status' => 'processed', 'processed_at' => now()]);
        } catch (\Throwable $exception) {
            if ($receipt) {
                $receipt->update(['status' => 'failed']);
            }
            throw $exception;
        } finally {
            $lock->release();
        }
    }

    protected function processEvent(): void
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

        if ($this->event === 'app.uninstalled') {
            $this->handleAppUninstalled($this->data);
            return;
        }

        // Find the Salla channel by merchant ID.
        //
        // NOTE: The OAuth callback (ChannelController::callbackSalla) stores the
        // merchant/store identity in `channels.page_id` (keyed as [user_id, type, page_id]),
        // the same way handleAppInstalled() below already resolves it. `channels.store_id`
        // is a column that exists in the schema but is never written anywhere in this
        // codebase, so querying by it can never match a real channel. Salla's webhook
        // payload carries the merchant id in the top-level `merchant` field (merged into
        // $this->data by SallaWebhookController), so we resolve against that + page_id,
        // consistent with how the app.installed handler already does it.
        $merchantId = $this->data['merchant'] ?? $this->data['store_id'] ?? null;
        if (!$merchantId) {
            Log::error('No merchant/store id in webhook data', ['data_keys' => array_keys($this->data)]);
            return;
        }

        $channel = Channel::where('type', 'salla')
            ->where('page_id', (string) $merchantId)
            ->first();

        if (!$channel) {
            Log::error('Salla channel not found for merchant', ['merchant_id' => $merchantId]);
            return;
        }

        if ($this->event === 'order.created') {
            $this->reconcilePendingOrderAttempt($channel, $this->data);
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

    /**
     * Match a provider-created order to an ambiguous local submission only when
     * the merchant, customer phone, product, and checkout state identify one
     * candidate. Ambiguous events are intentionally left unresolved.
     */
    protected function reconcilePendingOrderAttempt(Channel $channel, array $data): void
    {
        $orderId = $data['id'] ?? null;
        if (!$orderId) {
            return;
        }

        $customerPhone = $data['customer']['mobile']
            ?? $data['customer']['phone']
            ?? $data['receiver']['phone']
            ?? null;
        $normalizedPhone = preg_replace('/\\D+/', '', (string) $customerPhone);
        if ($normalizedPhone === '') {
            Log::warning('Salla order.created could not reconcile an order attempt: customer phone missing', [
                'channel_id' => $channel->id,
                'order_id' => (string) $orderId,
            ]);
            return;
        }

        $productIds = [];
        foreach (($data['items'] ?? $data['products'] ?? $data['order_products'] ?? []) as $item) {
            // `items[].id` is often the order-line ID, not the catalog product.
            $productId = $item['product']['id'] ?? $item['product_id'] ?? null;
            if ($productId !== null && is_scalar($productId)) {
                $productIds[] = (string) $productId;
            }
        }
        $productIds = array_values(array_unique($productIds));
        if ($productIds === []) {
            Log::warning('Salla order.created could not reconcile an order attempt: product identity missing', [
                'channel_id' => $channel->id,
                'order_id' => (string) $orderId,
            ]);
            return;
        }

        $candidates = [];
        $attempts = \App\Models\CommerceOrderAttempt::query()
            ->where('connection_id', $channel->id)
            ->where('provider', 'salla')
            ->whereIn('status', ['submitting', 'unknown'])
            ->where('created_at', '>=', now()->subHours(72))
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        foreach ($attempts as $attempt) {
            $conversation = Conversation::query()->find($attempt->conversation_id);
            $state = is_array($conversation?->checkout_state) ? $conversation->checkout_state : [];
            if (!$conversation || ($state['confirmation_state'] ?? null) !== 'confirmed') {
                continue;
            }

            $statePhone = preg_replace('/\\D+/', '', (string) ($state['phone'] ?? $state['customer_phone'] ?? ''));
            $stateProductIds = array_values(array_unique(array_filter(array_map(
                static fn (array $item): string => (string) ($item['salla_product_id'] ?? $item['product_id'] ?? ''),
                is_array($state['salla_line_items'] ?? null) ? $state['salla_line_items'] : []
            ))));
            if ($stateProductIds === []) {
                $singleProductId = (string) ($state['salla_product_id'] ?? $state['commerce_external_id'] ?? '');
                $stateProductIds = $singleProductId !== '' ? [$singleProductId] : [];
            }
            $productsMatch = $stateProductIds !== []
                && array_diff($stateProductIds, $productIds) === []
                && array_diff($productIds, $stateProductIds) === [];
            if ($statePhone === $normalizedPhone && $productsMatch) {
                $candidates[] = [$attempt, $conversation, $state];
            }
        }

        if (count($candidates) !== 1) {
            Log::warning('Salla order.created reconciliation was not unique; attempt remains unresolved', [
                'channel_id' => $channel->id,
                'order_id' => (string) $orderId,
                'candidate_count' => count($candidates),
            ]);
            return;
        }

        [$attempt, $conversation, $state] = $candidates[0];
        $attempt->update([
            'status' => 'completed',
            'external_order_id' => (string) $orderId,
            'order_number' => (string) ($data['reference_id'] ?? $orderId),
        ]);
        $state['status'] = 'completed';
        $state['order_id'] = (string) $orderId;
        $state['order_number'] = (string) ($data['reference_id'] ?? $orderId);
        $state['order_fingerprint'] = $attempt->checkout_fingerprint;
        $state['order_product_id'] = (string) ($state['salla_product_id'] ?? $state['commerce_external_id'] ?? '');
        $state['order_connection_id'] = (string) $channel->id;
        $state['confirmation_state'] = 'completed';
        $conversation->update(['checkout_state' => $state]);

        Log::info('Reconciled Salla order.created event with an ambiguous submission', [
            'channel_id' => $channel->id,
            'conversation_id' => $conversation->id,
            'attempt_id' => $attempt->id,
            'order_id' => (string) $orderId,
        ]);
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

            // Resolve/link the business-scoped Customer — sender_id IS the
            // customer's mobile, so Salla conversations match (and unify with
            // WhatsApp) by phone automatically.
            try {
                app(\App\Services\CustomerService::class)->attachToConversation($conversation);
            } catch (\Throwable $e) {
                Log::warning('Salla webhook: customer resolution failed', [
                    'conversation_id' => $conversation->id,
                    'exception_type' => get_class($e),
                ]);
            }

            // Trigger sequences with order-based triggers
            $sequenceTriggerService = app(SequenceTriggerService::class);
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

    /** Disconnect this store on uninstall while preserving conversation history. */
    protected function handleAppUninstalled(array $data): void
    {
        $merchantId = data_get($data, 'merchant.id')
            ?? (is_scalar($data['merchant'] ?? null) ? $data['merchant'] : null)
            ?? $data['merchant_id']
            ?? $data['store_id']
            ?? null;

        Log::info('Salla app.uninstalled webhook received', [
            'merchant_id' => $merchantId,
        ]);

        if (!$merchantId) {
            Log::error('Salla app.uninstalled: missing merchant ID in payload', ['data_keys' => array_keys($data)]);
            return;
        }

        $channels = Channel::where('type', 'salla')
            ->where('page_id', (string) $merchantId)
            ->limit(2)
            ->get();

        if ($channels->isEmpty()) {
            Log::info('Salla app.uninstalled: no channel found for merchant — already removed', [
                'merchant_id' => $merchantId,
            ]);
            return;
        }

        if ($channels->count() > 1) {
            Log::error('Salla app.uninstalled: ambiguous merchant channel mapping; cleanup skipped', [
                'merchant_id' => $merchantId,
                'matching_channel_count_at_least' => 2,
            ]);
            return;
        }

        $channel = $channels->first();
        // Salla revokes the merchant grant. Clear local credentials but retain
        // conversations, messages, customer identities, notes, and media.
        $this->disconnectUninstalledChannel($channel, (string) $merchantId);
        $conversationCount = Conversation::query()->where('channel_id', $channel->id)->count();
        $messageCount = Message::query()
            ->whereIn('conversation_id', Conversation::query()->select('id')->where('channel_id', $channel->id))
            ->count();
        $identityCount = CustomerIdentity::query()->where('channel_id', $channel->id)->count();

        Log::info('Salla app.uninstalled: channel disconnected; local history retained', [
            'channel_id' => $channel->id,
            'merchant_id' => $merchantId,
            'conversation_count' => $conversationCount,
            'message_count' => $messageCount,
            'identity_count' => $identityCount,
        ]);
    }

    private function disconnectUninstalledChannel(Channel $channel, string $merchantId): void
    {
        $channel->forceFill([
            'status' => 'disconnected',
            'access_token' => '',
            'refresh_token' => null,
            'token_expires_at' => null,
            'metadata' => array_merge($channel->metadata ?? [], [
                'uninstalled_at' => now()->toISOString(),
                'merchant_id' => $merchantId,
            ]),
        ])->save();
    }
}
