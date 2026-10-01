<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerNote;
use App\Services\AuditLoggerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CustomerController extends Controller
{
    /**
     * Resolve the business profile for the current request.
     */
    private function getResolvedBusinessProfile(Request $request): \App\Models\BusinessProfile
    {
        $user = $request->user();
        $requestedBusinessId = $request->header('X-Business-Id') ?? $request->input('business_id');

        if ($requestedBusinessId) {
            $requestedBusinessId = (int) $requestedBusinessId;
            $hasAccess = \App\Models\BusinessProfile::where('id', $requestedBusinessId)
                ->where('user_id', $user->id)
                ->exists() ||
                \App\Models\TeamMember::where('business_id', $requestedBusinessId)
                    ->where('user_id', $user->id)
                    ->where('is_active', true)
                    ->exists();

            if ($hasAccess) {
                return \App\Models\BusinessProfile::findOrFail($requestedBusinessId);
            }
        }

        return \App\Models\BusinessProfile::firstOrCreate(['user_id' => $user->id]);
    }

    /**
     * Get paginated list of customers for the authenticated user's business.
     */
    public function index(Request $request)
    {
        $business = $this->getResolvedBusinessProfile($request);

        $query = Customer::where('business_profile_id', $business->id);

        // Search filter
        if ($request->has('search') && !empty($request->search)) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        // Tag filter
        if ($request->has('tag') && !empty($request->tag)) {
            $query->whereJsonContains('tags', $request->tag);
        }

        $customers = $query->withCount('conversations')
            ->orderBy('created_at', 'desc')
            ->paginate(50);

        return response()->json($customers);
    }

    /**
     * Get customer details with conversations, notes, and orders.
     */
    public function show(Request $request, $id)
    {
        $business = $this->getResolvedBusinessProfile($request);

        $customer = Customer::where('business_profile_id', $business->id)
            ->with([
                'conversations' => function ($q) {
                    $q->orderBy('created_at', 'desc')->limit(10);
                },
                'notes' => function ($q) {
                    $q->with('author:id,name')->orderBy('created_at', 'desc')->limit(20);
                },
            ])
            ->findOrFail($id);

        // Load e-commerce orders from metadata (Salla/Shopify/WooCommerce)
        $orders = [];
        foreach ($customer->conversations as $conversation) {
            if (!empty($conversation->checkout_state['order_id'])) {
                $orders[] = [
                    'conversation_id' => $conversation->id,
                    'order_id' => $conversation->checkout_state['order_id'],
                    'product_name' => $conversation->checkout_state['product_name'] ?? null,
                    'total' => $conversation->checkout_state['product_price'] ?? null,
                    'status' => $conversation->checkout_state['status'] ?? 'unknown',
                    'created_at' => $conversation->created_at->toISOString(),
                ];
            }
        }

        return response()->json([
            'customer' => $customer,
            'orders' => $orders,
        ]);
    }

    /**
     * Store an internal note for a customer.
     */
    public function storeNote(Request $request, $customerId)
    {
        $business = $this->getResolvedBusinessProfile($request);

        $customer = Customer::where('business_profile_id', $business->id)->findOrFail($customerId);

        $request->validate([
            'content' => 'required|string|max:5000',
            'conversation_id' => 'nullable|integer|exists:conversations,id',
        ]);

        $note = CustomerNote::create([
            'customer_id' => $customer->id,
            'user_id' => Auth::id(),
            'conversation_id' => $request->input('conversation_id'),
            'content' => $request->input('content'),
        ]);

        // Audit log
        AuditLoggerService::log('customer.note_created', $note, [
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
        ]);

        return response()->json([
            'message' => 'Note created successfully',
            'note' => $note->load('author:id,name'),
        ], 201);
    }

    /**
     * Update customer tags and custom fields.
     */
    public function updateTags(Request $request, $customerId)
    {
        $business = $this->getResolvedBusinessProfile($request);

        $customer = Customer::where('business_profile_id', $business->id)->findOrFail($customerId);

        $request->validate([
            'tags' => 'nullable|array',
            'custom_fields' => 'nullable|array',
            'lead_score' => 'nullable|integer|min:0|max:100',
        ]);

        $customer->update($request->only(['tags', 'custom_fields', 'lead_score']));

        // Audit log
        AuditLoggerService::log('customer.updated', $customer, [
            'updated_fields' => array_keys($request->only(['tags', 'custom_fields', 'lead_score'])),
        ]);

        return response()->json([
            'message' => 'Customer updated successfully',
            'customer' => $customer->fresh(),
        ]);
    }
}
