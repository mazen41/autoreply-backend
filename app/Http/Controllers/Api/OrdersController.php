<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CommerceOrder;
use App\Services\ActiveBusiness;
use Illuminate\Http\Request;

class OrdersController extends Controller
{
    public function index(Request $request, ActiveBusiness $activeBusiness)
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'store_type' => ['nullable', 'in:salla,shopify,woocommerce'],
            'status' => ['nullable', 'string', 'max:50'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $business = $activeBusiness->resolve($request, permission: 'read');

        $orders = CommerceOrder::query()
            ->with('channel:id,type,page_name,store_name,page_id')
            ->where('business_id', $business->id)
            ->when($filters['store_type'] ?? null, fn ($query, $type) => $query->whereHas('channel', fn ($channels) => $channels->where('type', $type)))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['search'] ?? null, function ($query, $search) {
                $query->where(function ($matches) use ($search) {
                    $matches->where('order_number', 'like', "%{$search}%")
                        ->orWhere('external_id', 'like', "%{$search}%")
                        ->orWhere('customer_name', 'like', "%{$search}%")
                        ->orWhere('customer_email', 'like', "%{$search}%")
                        ->orWhere('customer_phone', 'like', "%{$search}%");
                });
            })
            ->orderByDesc('ordered_at')
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 25);

        $orders->getCollection()->transform(function (CommerceOrder $order) {
            $items = is_array($order->line_items) ? $order->line_items : [];
            $firstItem = $items[0] ?? [];
            $channel = $order->channel;

            return [
                'id' => (string) $order->id,
                'order_id' => $order->order_number ?: $order->external_id,
                'product_name' => $firstItem['name'] ?? $firstItem['title'] ?? null,
                'total' => (float) $order->total,
                'currency' => $order->currency ?: '',
                'status' => $order->status ?: 'pending',
                'store_type' => $channel?->type ?? 'unknown',
                'store_name' => $channel?->store_name ?: $channel?->page_name ?: $channel?->page_id,
                'customer_name' => $order->customer_name,
                'customer_phone' => $order->customer_phone,
                'created_at' => ($order->ordered_at ?: $order->created_at)?->toIso8601String(),
            ];
        });

        return response()->json($orders);
    }
}
