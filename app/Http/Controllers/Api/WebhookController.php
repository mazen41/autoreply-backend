<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Webhook;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class WebhookController extends Controller
{
    /**
     * Get webhooks for a business
     */
    public function index(Request $request, $businessId)
    {
        $webhooks = Webhook::where('business_id', $businessId)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($webhooks);
    }

    /**
     * Create a new webhook
     */
    public function store(Request $request, $businessId)
    {
        $request->validate([
            'url' => 'required|url',
            'events' => 'required|array',
            'secret' => 'nullable|string',
        ]);

        $webhook = Webhook::create([
            'business_id' => $businessId,
            'url' => $request->url,
            'events' => $request->events,
            'secret' => $request->secret,
            'is_active' => true,
        ]);

        return response()->json(['success' => true, 'webhook' => $webhook]);
    }

    /**
     * Update a webhook
     */
    public function update(Request $request, $businessId, $webhookId)
    {
        $request->validate([
            'url' => 'sometimes|url',
            'events' => 'sometimes|array',
            'secret' => 'nullable|string',
            'is_active' => 'sometimes|boolean',
        ]);

        $webhook = Webhook::where('business_id', $businessId)
            ->findOrFail($webhookId);

        $webhook->update($request->only(['url', 'events', 'secret', 'is_active']));

        return response()->json(['success' => true, 'webhook' => $webhook]);
    }

    /**
     * Delete a webhook
     */
    public function destroy(Request $request, $businessId, $webhookId)
    {
        $webhook = Webhook::where('business_id', $businessId)
            ->findOrFail($webhookId);

        $webhook->delete();

        return response()->json(['success' => true]);
    }

    /**
     * Test a webhook
     */
    public function test(Request $request, $businessId, $webhookId)
    {
        $webhook = Webhook::where('business_id', $businessId)
            ->findOrFail($webhookId);

        $testPayload = [
            'test' => true,
            'message' => 'Webhook test from Naz platform',
            'timestamp' => now()->toISOString(),
        ];

        try {
            $webhookService = new \App\Services\WebhookService();
            $webhookService->sendWebhook($webhook, 'test', $testPayload);

            return response()->json(['success' => true, 'message' => 'Test webhook sent']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }
}
