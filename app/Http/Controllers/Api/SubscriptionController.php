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

    public function createFree(Request $request)
    {
        $user = auth()->user();
        
        // Check if user already has an active subscription
        if ($user->activeSubscription) {
            return response()->json(['message' => 'User already has an active subscription'], 400);
        }

        $validated = $request->validate([
            'package_id' => 'required|exists:packages,id',
            'billing_cycle' => 'required|in:monthly,yearly',
        ]);

        $package = Package::findOrFail($validated['package_id']);

        // Only allow free packages
        if ($package->price_monthly > 0 || $package->price_yearly > 0) {
            return response()->json(['message' => 'This endpoint is only for free plans'], 400);
        }

        $subscription = Subscription::create([
            'user_id' => $user->id,
            'package_id' => $package->id,
            'billing_cycle' => $validated['billing_cycle'],
            'status' => 'active',
            'starts_at' => now(),
            'ends_at' => now()->addMonth(), // Free plans renew monthly
            'amount_paid' => 0,
        ]);

        return response()->json([
            'subscription' => $subscription->load('package'),
            'package' => $package,
            'message' => 'Free subscription created successfully'
        ]);
    }
}
