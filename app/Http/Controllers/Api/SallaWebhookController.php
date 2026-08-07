<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SallaWebhookJob;
use App\Services\SallaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class SallaWebhookController extends Controller
{
    /**
     * Handle incoming Salla webhook
     */
    public function handle(Request $request)
    {
        Log::info('=== SALLA WEBHOOK RECEIVED ===');
        
        // Log all headers for debugging
        Log::info('Webhook headers', [
            'all_headers' => $request->headers->all(),
            'content_type' => $request->header('Content-Type'),
        ]);

        // Get webhook signature from different possible headers
        $signature = $request->header('X-Salla-Signature') 
                    ?? $request->header('X-Salla-Hmac-Sha256')
                    ?? $request->header('Signature')
                    ?? null;
        
        $payload = $request->getContent();

        // Verify webhook signature if present
        if ($signature) {
            $sallaService = new SallaService();
            if (!$sallaService->verifyWebhookSignature($payload, $signature)) {
                Log::error('Invalid Salla webhook signature');
                return response()->json(['error' => 'Invalid signature'], 401);
            }
        } else {
            Log::warning('No webhook signature found, proceeding without verification');
        }

        // Get event data - Salla might send different formats
        $event = $request->input('event') ?? $request->input('type');
        $data = $request->input('data', []);

        Log::info('Salla webhook event', [
            'event' => $event,
            'data_keys' => array_keys($data),
            'payload_preview' => substr($payload, 0, 500),
        ]);

        // Dispatch job for async processing
        try {
            SallaWebhookJob::dispatch($event, $data);
            Log::info('SallaWebhookJob dispatched successfully');
        } catch (\Exception $e) {
            Log::error('Failed to dispatch SallaWebhookJob', [
                'error' => $e->getMessage(),
            ]);
            return response()->json(['error' => 'Failed to process webhook'], 500);
        }

        return response()->json(['message' => 'Webhook received']);
    }
}
