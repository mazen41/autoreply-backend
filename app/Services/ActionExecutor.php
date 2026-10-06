<?php

namespace App\Services;

use App\Models\AiActionLog;
use App\Models\PendingAction;
use App\Models\Product;
use App\Models\Conversation;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class ActionExecutor
{
    /**
     * Execute an AI-triggered action
     */
    public function executeAction(array $actionData, int $conversationId, int $messageId): array
    {
        $actionType = $actionData['action'] ?? null;
        $payload = $actionData['data'] ?? [];

        // Validate action structure
        if (!$actionType) {
            return [
                'success' => false,
                'error' => 'Invalid action: missing action type',
            ];
        }

        // Log the action attempt
        $actionLog = AiActionLog::create([
            'conversation_id' => $conversationId,
            'message_id' => $messageId,
            'action_type' => $actionType,
            'action_payload' => $payload,
            'status' => 'pending',
        ]);

        try {
            // Execute based on action type
            $result = $this->executeActionByType($actionType, $payload, $conversationId);

            // Update action log
            $actionLog->update([
                'status' => $result['success'] ? 'executed' : 'failed',
                'result' => $result,
                'error_message' => $result['error'] ?? null,
                'executed_at' => now(),
            ]);

            return $result;
        } catch (\Exception $e) {
            Log::error('ActionExecutor: Exception', [
                'action_type' => $actionType,
                'error' => $e->getMessage(),
            ]);

            $actionLog->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'executed_at' => now(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Execute action by type (PHP 8.0 compatible)
     */
    private function executeActionByType(string $actionType, array $payload, int $conversationId): array
    {
        switch ($actionType) {
            case 'create_order':
                return $this->createOrder($payload, $conversationId);
            case 'get_products':
                return $this->getProducts($payload, $conversationId);
            case 'check_status':
                return $this->checkStatus($payload, $conversationId);
            case 'book_appointment':
                return $this->bookAppointment($payload, $conversationId);
            default:
                return $this->handleUnknownAction($actionType, $payload);
        }
    }

    /**
     * Create an order
     */
    private function createOrder(array $payload, int $conversationId): array
    {
        // Commerce orders must pass through the provider-backed conversation
        // checkout path, which owns store authorization and idempotency.
        return [
            'success' => false,
            'error' => 'Order creation must be completed through the conversation checkout flow.',
        ];
    }

    /**
     * Get products information
     */
    private function getProducts(array $payload, int $conversationId): array
    {
        $conversation = Conversation::find($conversationId);
        if (!$conversation || !$conversation->business) {
            return ['success' => false, 'error' => 'Conversation business context is unavailable.'];
        }
        $business = $conversation->business;

        $commerceContext = app(EcommerceChannelResolver::class)->resolveConversation($conversation);
        $connection = $commerceContext['connection'] ?? null;
        if (($commerceContext['status'] ?? 'unresolved') !== 'resolved'
            || !$connection
            || (int) $connection->business_id !== (int) $conversation->business_id) {
            return ['success' => false, 'error' => 'No authorized commerce store is resolved for this conversation.'];
        }

        $query = Product::where('business_id', $business->id)
            ->where('commerce_channel_id', $connection->id)
            ->active();

        // Apply filters if provided
        if (isset($payload['category'])) {
            $query->whereJsonContains('metadata', ['category' => $payload['category']]);
        }

        if (isset($payload['min_price'])) {
            $query->where('price', '>=', $payload['min_price']);
        }

        if (isset($payload['max_price'])) {
            $query->where('price', '<=', $payload['max_price']);
        }

        $products = $query->get();

        return [
            'success' => true,
            'products' => $products->map(function ($product) {
                return [
                    'id' => $product->id,
                    'name' => $product->name,
                    'price' => $product->price,
                    'stock' => $product->stock_quantity,
                    'available' => $product->stock_quantity > 0,
                ];
            })->toArray(),
        ];
    }

    /**
     * Check order/booking status
     */
    private function checkStatus(array $payload, int $conversationId): array
    {
        $orderId = $payload['order_id'] ?? null;
        if (!$orderId) {
            return ['success' => false, 'error' => 'Order ID required'];
        }

        $conversation = Conversation::find($conversationId);
        if (!$conversation || !$conversation->business) {
            return ['success' => false, 'error' => 'Conversation business context is unavailable.'];
        }

        $commerceContext = app(EcommerceChannelResolver::class)->resolveConversation($conversation);
        $connection = $commerceContext['connection'] ?? null;
        if (($commerceContext['status'] ?? 'unresolved') !== 'resolved' || !$connection) {
            return ['success' => false, 'error' => 'No authorized commerce store is resolved for this conversation.'];
        }

        $order = DB::table('commerce_orders')
            ->where('business_id', $conversation->business_id)
            ->where('channel_id', $connection->id)
            ->where(function ($query) use ($orderId) {
                $query->where('external_id', (string) $orderId)
                    ->orWhere('order_number', (string) $orderId);
            })
            ->first();
        if (!$order) {
            return ['success' => false, 'error' => 'Order was not found in this store.'];
        }

        return [
            'success' => true,
            'order_id' => $order->external_id,
            'order_number' => $order->order_number,
            'status' => $order->status,
            'fulfillment_status' => $order->fulfillment_status,
        ];
    }

    /**
     * Book an appointment
     */
    private function bookAppointment(array $payload, int $conversationId): array
    {
        $validator = Validator::make($payload, [
            'date' => 'required|date',
            'time' => 'required',
            'duration' => 'required|integer|min:15',
        ]);

        if ($validator->fails()) {
            return [
                'success' => false,
                'error' => 'Invalid appointment data: ' . $validator->errors()->first(),
            ];
        }

        $conversation = Conversation::find($conversationId);
        $business = $conversation->business;

        // Create calendar event
        $event = \App\Models\CalendarEvent::create([
            'business_id' => $business->id,
            'conversation_id' => $conversationId,
            'title' => 'Appointment',
            'description' => 'Appointment booked via AI chat',
            'start_time' => $payload['date'] . ' ' . $payload['time'],
            'end_time' => date('Y-m-d H:i:s', strtotime($payload['date'] . ' ' . $payload['time'] . " +{$payload['duration']} minutes")),
            'status' => 'confirmed',
        ]);

        return [
            'success' => true,
            'event_id' => $event->id,
            'message' => 'تم حجز الموعد بنجاح ✅',
        ];
    }

    /**
     * Handle unknown actions
     */
    private function handleUnknownAction(string $actionType, array $payload): array
    {
        return [
            'success' => false,
            'error' => "Unknown action type: {$actionType}",
        ];
    }

    /**
     * Queue a pending action for later execution
     */
    public function queueAction(array $actionData, int $conversationId, string $priority = 'medium'): void
    {
        PendingAction::create([
            'conversation_id' => $conversationId,
            'action_type' => $actionData['action'],
            'action_payload' => $actionData['data'] ?? [],
            'priority' => $priority,
            'status' => 'pending',
        ]);
    }
}
