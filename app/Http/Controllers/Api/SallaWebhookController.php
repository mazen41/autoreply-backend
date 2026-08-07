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
        
        // Check for Token-based authentication (Salla's Token strategy)
        $authToken = $request->header('authorization');
        $securityStrategy = $request->header('x-salla-security-strategy');
        
        $payload = $request->getContent();

        // Verify webhook signature if present
        if ($signature) {
            $sallaService = new SallaService();
            if (!$sallaService->verifyWebhookSignature($payload, $signature)) {
                Log::error('Invalid Salla webhook signature');
                return response()->json(['error' => 'Invalid signature'], 401);
            }
        } elseif ($securityStrategy === 'Token' && $authToken) {
            // Verify token for Token-based authentication
            $webhookSecret = env('SALLA_WEBHOOK_SECRET');
            if ($authToken !== $webhookSecret) {
                Log::error('Invalid Salla webhook token', [
                    'provided' => substr($authToken, 0, 10) . '...',
                    'expected' => substr($webhookSecret, 0, 10) . '...',
                ]);
                return response()->json(['error' => 'Invalid token'], 401);
            }
            Log::info('Salla webhook token verified successfully');
        } else {
            Log::warning('No webhook signature or token found, proceeding without verification');
        }

        // Get event data - Salla might send different formats
        $event = $request->input('event') ?? $request->input('type');
        // $data is the sub-key payload, but we also need top-level fields
        // like `merchant` (present in app.installed). Merge them so the job
        // always has the full picture.
        $data = $request->input('data', []);
        $topLevel = $request->except(['event', 'type', 'data']);
        $fullData = array_merge($topLevel, $data); // sub-key wins on collision

        Log::info('Salla webhook event', [
            'event' => $event,
            'data_keys' => array_keys($fullData),
            'payload_preview' => substr($payload, 0, 500),
        ]);

        // Dispatch job for async processing
        try {
            SallaWebhookJob::dispatch($event, $fullData);
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
