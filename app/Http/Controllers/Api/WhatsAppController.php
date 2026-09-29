<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\EvolutionApiService;
use App\Models\WhatsAppInstance;
use App\Models\WhatsAppMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Exception;

class WhatsAppController extends Controller
{
    protected EvolutionApiService $evolutionService;

    public function __construct(EvolutionApiService $evolutionService)
    {
        $this->evolutionService = $evolutionService;
    }

    /**
     * Get current user's WhatsApp instance status
     */
    public function status(Request $request)
    {
        $user = Auth::user();
        $instance = $this->resolveInstance($request, $user->id);

        if (!$instance) {
            return response()->json([
                'connected' => false,
                'instance' => null,
            ]);
        }

        // Refresh status from Evolution API
        try {
            $connectionState = $this->evolutionService->getConnectionState($instance->instance_name);
            $state = $connectionState['state'] ?? null;

            if ($state) {
                $instance->status = match ($state) {
                    'open' => 'connected',
                    'close' => 'disconnected',
                    'connecting' => 'connecting',
                    default => $instance->status,
                };

                if ($state === 'open' && !$instance->connected_at) {
                    $instance->connected_at = now();
                } elseif ($state === 'close') {
                    $instance->disconnected_at = now();
                }

                $instance->save();
            }
        } catch (Exception $e) {
            Log::error("Failed to refresh instance status: {$e->getMessage()}");
        }

        return response()->json([
            'connected' => $instance->isConnected(),
            'instance' => $instance,
        ]);
    }

    /**
     * Connect WhatsApp - create a new instance and return QR code.
     *
     * Multi-account: a user may connect multiple WhatsApp numbers. Each call
     * creates a brand-new instance. We do NOT block if a connected instance
     * already exists — the caller is intentionally adding a second account.
     *
     * We only clean up a stale pending/connecting row (one that never reached
     * 'connected') to avoid leaving orphaned Evolution instances. We clean
     * exactly that one row, never all rows for the user.
     */
    public function connect(Request $request)
    {
        $user = Auth::user();

        // Clean up the most recent pending/connecting instance for this user if
        // it is not yet connected (e.g. user abandoned the QR scan).
        // Do NOT touch connected instances — those are active sibling accounts.
        $stalePending = WhatsAppInstance::forUser($user->id)
            ->whereIn('status', ['pending', 'connecting'])
            ->latest()
            ->first();

        if ($stalePending) {
            try {
                $this->evolutionService->deleteInstance($stalePending->instance_name);
            } catch (Exception $cleanupError) {
                Log::warning("Failed to delete stale Evolution instance during reconnect: {$cleanupError->getMessage()}", [
                    'instance' => $stalePending->instance_name,
                ]);
            }
            $stalePending->delete();
        }

        // Generate a unique instance name: user_{id}_{timestamp}_{4-char hex}.
        // The hex suffix prevents collisions when two connects happen within the
        // same second (e.g. a UI retry).
        $instanceName = "user_{$user->id}_" . time() . '_' . bin2hex(random_bytes(2));

        try {
            // Create Evolution instance
            $evolutionResponse = $this->evolutionService->createInstance($instanceName);

            if (!isset($evolutionResponse['instance']) || !isset($evolutionResponse['qrcode'])) {
                throw new Exception('Invalid response from Evolution API');
            }

            // Save instance to database
            $instance = WhatsAppInstance::create([
                'user_id' => $user->id,
                'instance_name' => $instanceName,
                'status' => 'connecting',
                'evolution_api_token' => $evolutionResponse['instance']['token'] ?? null,
                'evolution_instance_id' => $evolutionResponse['instance']['instanceName'] ?? $instanceName,
                'webhook_url' => $evolutionResponse['instance']['webhook'] ?? null,
                'metadata' => [
                    'evolution_response' => $evolutionResponse,
                ],
            ]);

            return response()->json([
                'message' => 'Instance created successfully',
                'instance' => $instance,
                'qrcode' => $evolutionResponse['qrcode']['base64'] ?? $evolutionResponse['qrcode']['pairingCode'] ?? null,
                'pairing_code' => $evolutionResponse['qrcode']['pairingCode'] ?? null,
            ]);

        } catch (Exception $e) {
            Log::error("Failed to create WhatsApp instance: {$e->getMessage()}");
            
            // Clean up if instance was created in Evolution but failed to save
            try {
                $this->evolutionService->deleteInstance($instanceName);
            } catch (Exception $cleanupError) {
                Log::error("Failed to cleanup instance: {$cleanupError->getMessage()}");
            }

            return response()->json([
                'message' => 'Failed to create WhatsApp instance',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get QR code for existing instance
     */
    public function getQrCode(Request $request)
    {
        $user = Auth::user();
        $instance = $this->resolveInstance($request, $user->id);

        if (!$instance) {
            return response()->json(['message' => 'No instance found'], 404);
        }

        if ($instance->isConnected()) {
            return response()->json(['message' => 'Already connected'], 400);
        }

        try {
            $qrResponse = $this->evolutionService->getQrCode($instance->instance_name);

            return response()->json([
                'qrcode' => $qrResponse['base64'] ?? $qrResponse['pairingCode'] ?? null,
                'pairing_code' => $qrResponse['pairingCode'] ?? null,
            ]);

        } catch (Exception $e) {
            Log::error("Failed to get QR code: {$e->getMessage()}");
            return response()->json([
                'message' => 'Failed to get QR code',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Disconnect WhatsApp
     */
    public function disconnect(Request $request)
    {
        $user = Auth::user();
        $instance = $this->resolveInstance($request, $user->id);

        if (!$instance) {
            return response()->json(['message' => 'No instance found'], 404);
        }

        try {
            // Logout from Evolution API
            $this->evolutionService->logoutInstance($instance->instance_name);

            // Update instance status
            $instance->status = 'disconnected';
            $instance->disconnected_at = now();
            $instance->save();

            return response()->json([
                'message' => 'WhatsApp disconnected successfully',
                'instance' => $instance,
            ]);

        } catch (Exception $e) {
            Log::error("Failed to disconnect WhatsApp: {$e->getMessage()}");
            
            // Still update local status even if API call fails
            $instance->status = 'disconnected';
            $instance->disconnected_at = now();
            $instance->save();

            return response()->json([
                'message' => 'WhatsApp disconnected (API call failed)',
                'instance' => $instance,
            ]);
        }
    }

    /**
     * Reconnect WhatsApp
     */
    public function reconnect(Request $request)
    {
        // connect() already handles cleaning up any existing stale instance
        // before creating a fresh one, so reconnect is just an alias for it.
        return $this->connect($request);
    }

    /**
     * Send a message
     */
    public function sendMessage(Request $request)
    {
        $request->validate([
            'number' => 'required|string',
            'message' => 'required|string',
            'type' => 'sometimes|in:text,media',
            'media_url' => 'required_if:type,media|url',
            'caption' => 'sometimes|string',
        ]);

        $user = Auth::user();
        $instance = $this->resolveInstance($request, $user->id);

        if (!$instance || !$instance->isConnected()) {
            return response()->json(['message' => 'WhatsApp not connected'], 400);
        }

        try {
            $number = $request->number;
            $message = $request->message;
            $type = $request->type ?? 'text';

            if ($type === 'media') {
                $response = $this->evolutionService->sendMediaMessage(
                    $instance->instance_name,
                    $number,
                    $request->media_url,
                    $request->caption ?? ''
                );
            } else {
                $response = $this->evolutionService->sendTextMessage(
                    $instance->instance_name,
                    $number,
                    $message
                );
            }

            // Save outgoing message to database
            $savedMessage = WhatsAppMessage::create([
                'whatsapp_instance_id' => $instance->id,
                'user_id' => $user->id,
                'message_id' => $response['key']['id'] ?? null,
                'remote_message_id' => $response['key']['id'] ?? null,
                'direction' => 'outgoing',
                'from_phone' => $instance->phone_number,
                'to_phone' => $number,
                'body' => $type === 'media' ? ($request->caption ?? '') : $message,
                'message_type' => $type,
                'media' => $type === 'media' ? [
                    'url' => $request->media_url,
                    'caption' => $request->caption ?? '',
                ] : null,
                'metadata' => [
                    'evolution_response' => $response,
                ],
                'status' => 'pending',
                'sent_at' => now(),
            ]);

            return response()->json([
                'message' => 'Message sent successfully',
                'data' => $response,
                'saved_message' => $savedMessage,
            ]);

        } catch (Exception $e) {
            Log::error("Failed to send message: {$e->getMessage()}");
            return response()->json([
                'message' => 'Failed to send message',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get messages for the current user
     */
    public function getMessages(Request $request)
    {
        $user = Auth::user();
        $instance = $this->resolveInstance($request, $user->id);

        if (!$instance) {
            return response()->json(['message' => 'No instance found'], 404);
        }

        $messages = WhatsAppMessage::forInstance($instance->id)
            ->forUser($user->id)
            ->latest()
            ->paginate($request->per_page ?? 50);

        return response()->json($messages);
    }

    /**
     * Get instance details
     */
    public function getInstance(Request $request)
    {
        $user = Auth::user();
        $instance = $this->resolveInstance($request, $user->id);

        if (!$instance) {
            return response()->json(['message' => 'No instance found'], 404);
        }

        try {
            $evolutionInstance = $this->evolutionService->fetchInstance($instance->instance_name);

            return response()->json([
                'instance' => $instance,
                'evolution_data' => $evolutionInstance[0] ?? null,
            ]);

        } catch (Exception $e) {
            Log::error("Failed to fetch instance details: {$e->getMessage()}");
            return response()->json([
                'message' => 'Failed to fetch instance details',
                'instance' => $instance,
            ]);
        }
    }

    /**
     * Handle Evolution API webhook events
     */
    public function webhook(Request $request)
    {
        try {
            $event = $request->all();

            // Log only safe metadata — never the full payload (may contain
            // base64 image data, media URLs, or credential material).
            Log::info('Evolution webhook received', [
                'event_type' => $event['event'] ?? 'unknown',
                'instance'   => $event['instance'] ?? 'unknown',
                'data_keys'  => array_keys($event['data'] ?? []),
            ]);

            $this->evolutionService->processWebhookEvent($event);

            return response()->json(['message' => 'Webhook processed successfully']);
        } catch (Exception $e) {
            Log::error("Failed to process webhook: {$e->getMessage()}");
            return response()->json(['message' => 'Failed to process webhook'], 500);
        }
    }

    /**
     * Resolve a WhatsApp instance for the authenticated user.
     *
     * Multi-account: callers may pass ?instance_name=<name> or ?channel_id=<id>
     * to target a specific account. Without either param the method falls back
     * to the most-recently-created instance (preserving single-account
     * backward-compatibility for existing frontends that don't pass a selector).
     */
    private function resolveInstance(Request $request, int $userId): ?WhatsAppInstance
    {
        $query = WhatsAppInstance::forUser($userId);

        if ($request->filled('instance_name')) {
            return $query->where('instance_name', $request->input('instance_name'))->first();
        }

        if ($request->filled('channel_id')) {
            // channel_id refers to channels.id; the page_id on that Channel row
            // is the instance_name stored in WhatsAppInstance.
            $channel = \App\Models\Channel::where('id', $request->input('channel_id'))
                ->where('user_id', $userId)
                ->where('type', 'whatsapp')
                ->first();

            if ($channel) {
                return $query->where('instance_name', $channel->page_id)->first();
            }

            return null;
        }

        // Fallback: latest instance (single-account compat).
        return $query->latest()->first();
    }
}
