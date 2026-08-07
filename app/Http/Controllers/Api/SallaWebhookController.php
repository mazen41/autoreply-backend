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
        
        // Get webhook signature from header
        $signature = $request->header('X-Salla-Signature');
        $payload = $request->getContent();

        // Verify webhook signature
        $sallaService = new SallaService();
        if (!$sallaService->verifyWebhookSignature($payload, $signature)) {
            Log::error('Invalid Salla webhook signature');
            return response()->json(['error' => 'Invalid signature'], 401);
        }

        // Get event data
        $event = $request->input('event');
        $data = $request->input('data', []);

        Log::info('Salla webhook event', [
            'event' => $event,
            'data_keys' => array_keys($data),
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
