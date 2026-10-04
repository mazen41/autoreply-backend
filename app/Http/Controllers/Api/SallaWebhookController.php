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
        $webhookSecret = env('SALLA_WEBHOOK_SECRET');

        // Phase 4: every response now carries an EXPLICIT verification/
        // configuration status. The previous version returned a plain
        // {"message": "Webhook received"} even when the payload was accepted
        // with NO verification at all — a silent success that made an
        // unconfigured webhook look healthy in monitoring.
        $verified = false;
        $verificationMode = 'none';
        $warning = null;

        if ($signature) {
            if (empty($webhookSecret)) {
                // A signature was SENT but we cannot verify it — this is a
                // configuration error, not an invalid signature. Fail loudly.
                Log::error('Salla webhook: signature present but SALLA_WEBHOOK_SECRET not configured');
                return response()->json([
                    'status'            => 'rejected',
                    'verified'          => false,
                    'verification_mode' => 'signature',
                    'error'             => 'webhook_secret_not_configured',
                ], 500);
            }

            $sallaService = new SallaService();
            if (!$sallaService->verifyWebhookSignature($payload, $signature)) {
                Log::error('Invalid Salla webhook signature');
                return response()->json([
                    'status'            => 'rejected',
                    'verified'          => false,
                    'verification_mode' => 'signature',
                    'error'             => 'invalid_signature',
                ], 401);
            }
            $verified = true;
            $verificationMode = 'signature';
        } elseif ($securityStrategy === 'Token' && $authToken) {
            // Token strategy REQUIRES the secret to be configured — if it is
            // missing this is a configuration error, not an auth failure.
            if (empty($webhookSecret)) {
                Log::error('Salla webhook: Token strategy selected but SALLA_WEBHOOK_SECRET not configured');
                return response()->json([
                    'status'            => 'rejected',
                    'verified'          => false,
                    'verification_mode' => 'token',
                    'error'             => 'webhook_secret_not_configured',
                ], 500);
            }
            if ($authToken !== $webhookSecret) {
                Log::error('Invalid Salla webhook token', [
                    'provided' => substr($authToken, 0, 10) . '...',
                ]);
                return response()->json([
                    'status'            => 'rejected',
                    'verified'          => false,
                    'verification_mode' => 'token',
                    'error'             => 'invalid_token',
                ], 401);
            }
            Log::info('Salla webhook token verified successfully');
            $verified = true;
            $verificationMode = 'token';
        } else {
            // Fail-open (an unconfigured secret must not silently drop ALL
            // intake — mirrors the Meta webhook controller's posture), but the
            // acceptance is now EXPLICITLY marked unverified in both the log
            // stream and the response body.
            $warning = 'Payload accepted UNVERIFIED: no signature header and no security strategy token. '
                . 'Configure SALLA_WEBHOOK_SECRET and the Salla webhook signing secret to enforce verification.';
            Log::warning('Salla webhook accepted WITHOUT verification (secret not configured / no signature header)', [
                'event' => $request->input('event') ?? $request->input('type'),
                'has_signature_header' => false,
            ]);
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
            Log::info('SallaWebhookJob dispatched successfully', [
                'verified' => $verified,
                'verification_mode' => $verificationMode,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to dispatch SallaWebhookJob', [
                'error' => $e->getMessage(),
            ]);
            return response()->json([
                'status' => 'error',
                'verified' => $verified,
                'verification_mode' => $verificationMode,
                'error' => 'failed_to_process_webhook',
            ], 500);
        }

        return response()->json([
            'status'            => 'accepted',
            'verified'          => $verified,
            'verification_mode' => $verificationMode,
            'warning'           => $warning,
        ]);
    }
}
