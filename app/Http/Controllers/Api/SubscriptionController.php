<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Package;
use App\Models\Subscription;
use Illuminate\Http\Request;

class SubscriptionController extends Controller
{
    public function current()
    {
        $user = auth()->user();
        $subscription = $user->activeSubscription;

        if (!$subscription) {
            // Return Free package as default
            $freePackage = Package::where('name', 'Free')->first();
            return response()->json([
                'subscription' => null,
                'package' => $freePackage,
                'is_free' => true
            ]);
        }

        return response()->json([
            'subscription' => $subscription->load('package'),
            'package' => $subscription->package,
            'is_free' => false
        ]);
    }

    public function cancel()
    {
        $user = auth()->user();
        $subscription = $user->activeSubscription;

        if (!$subscription) {
            return response()->json(['message' => 'No active subscription found'], 404);
        }

        $subscription->update([
            'status' => 'cancelled',
            'cancelled_at' => now()
        ]);

        return response()->json(['message' => 'Subscription cancelled successfully']);
    }
}
