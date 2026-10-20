<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Pusher\Pusher;

class PusherAuthController extends Controller
{
    protected $pusher;

    public function __construct()
    {
        $this->pusher = new Pusher(
            config('broadcasting.connections.pusher.key'),
            config('broadcasting.connections.pusher.secret'),
            config('broadcasting.connections.pusher.app_id'),
            [
                'cluster' => config('broadcasting.connections.pusher.options.cluster'),
                'useTLS' => config('broadcasting.connections.pusher.options.useTLS', true),
            ]
        );
    }

    public function authenticate(Request $request)
    {
        // Delegate authorization to Laravel's registered channel callbacks.
        return Broadcast::auth($request);
    }

    public function testConnection()
    {
        try {
            // Test Pusher connection by triggering a test event
            $this->pusher->trigger('test-channel', 'test-event', [
                'message' => 'Connection test successful',
                'timestamp' => now()->toIso8601String(),
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Pusher connection test successful'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Pusher connection failed: ' . $e->getMessage()
            ], 500);
        }
    }
}
