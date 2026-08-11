<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
        $socketId = $request->input('socket_id');
        $channelName = $request->input('channel_name');

        // For private channels, check authentication
        if (str_starts_with($channelName, 'private-')) {
            $user = Auth::guard('sanctum')->user();
            
            if (!$user) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }

            // Extract business ID from channel name if present
            // Example: private-business.1
            if (preg_match('/private-business\.(\d+)/', $channelName, $matches)) {
                $businessId = $matches[1];
                
                // Check if user has access to this business
                if (!$user->businessProfiles()->where('id', $businessId)->exists()) {
                    return response()->json(['error' => 'Forbidden'], 403);
                }
            }

            $auth = $this->pusher->authorizeChannel($channelName, $socketId);
            return response()->json($auth);
        }

        // For presence channels, additional user info
        if (str_starts_with($channelName, 'presence-')) {
            $user = Auth::guard('sanctum')->user();
            
            if (!$user) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }

            $presenceData = [
                'user_id' => $user->id,
                'user_info' => [
                    'name' => $user->name,
                    'email' => $user->email,
                ],
            ];

            $auth = $this->pusher->authorizeChannel(
                $channelName, 
                $socketId, 
                $presenceData
            );
            
            return response()->json($auth);
        }

        // Public channels don't need authentication
        return response()->json(['auth' => ':']);
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