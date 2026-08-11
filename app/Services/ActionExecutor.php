<?php

namespace App\Services;

use App\Models\AiActionLog;
use App\Models\PendingAction;
use App\Models\Product;
use App\Models\Conversation;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

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
        $validator = Validator::make($payload, [
            'product_id' => 'required|integer',
            'quantity' => 'required|integer|min:1',
        ]);

        if ($validator->fails()) {
            return [
                'success' => false,
                'error' => 'Invalid order data: ' . $validator->errors()->first(),
            ];
        }

        $conversation = Conversation::find($conversationId);
        $business = $conversation->business;

        // Check if product exists and belongs to business
        $product = Product::where('business_id', $business->id)
            ->where('id', $payload['product_id'])
            ->first();

        if (!$product) {
            return [
                'success' => false,
                'error' => 'Product not found',
            ];
        }

        // Check stock
        if ($product->stock_quantity < $payload['quantity']) {
            return [
                'success' => false,
                'error' => 'Insufficient stock',
            ];
        }

        // Create order (simplified - in real system, you'd have an orders table)
        $orderId = 'ORD-' . time() . '-' . rand(1000, 9999);

        // Update stock
        $product->decrement('stock_quantity', $payload['quantity']);

        return [
            'success' => true,
            'order_id' => $orderId,
            'product' => $product->name,
            'quantity' => $payload['quantity'],
            'total' => $product->price * $payload['quantity'],
            'message' => "تم إنشاء الطلب رقم {$orderId} ✅",
        ];
    }

    /**
     * Get products information
     */
    private function getProducts(array $payload, int $conversationId): array
    {
        $conversation = Conversation::find($conversationId);
        $business = $conversation->business;

        $query = Product::where('business_id', $business->id)->active();

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
            return [
                'success' => false,
                'error' => 'Order ID required',
            ];
        }

        // In real system, you'd check actual order status
        // For now, return mock status
        return [
            'success' => true,
            'order_id' => $orderId,
            'status' => 'processing',
            'message' => 'الطلب قيد المعالجة',
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
