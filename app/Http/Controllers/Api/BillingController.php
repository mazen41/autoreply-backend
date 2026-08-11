<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\BillingService;
use App\Models\Subscription;
use App\Models\Overage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class BillingController extends Controller
{
    private $billingService;

    public function __construct(BillingService $billingService)
    {
        $this->billingService = $billingService;
    }

    /**
     * Get current subscription status
     */
    public function getCurrentSubscription(Request $request)
    {
        $subscription = Subscription::where('user_id', Auth::id())
            ->with('package')
            ->orderBy('created_at', 'desc')
            ->first();

        if (!$subscription) {
            return response()->json(['error' => 'No active subscription'], 404);
        }

        return response()->json($subscription);
    }

    /**
     * Get usage statistics
     */
    public function getUsageStats(Request $request)
    {
        $subscription = Subscription::where('user_id', Auth::id())
            ->with('package')
            ->orderBy('created_at', 'desc')
            ->first();

        if (!$subscription) {
            return response()->json(['error' => 'No active subscription'], 404);
        }

        $usageLimit = $subscription->package->ai_replies_limit ?? 0;
        $usageCount = $subscription->usage_count ?? 0;
        $percentage = $usageLimit > 0 ? round(($usageCount / $usageLimit) * 100, 2) : 0;

        $overages = Overage::where('user_id', Auth::id())
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'usage_count' => $usageCount,
            'usage_limit' => $usageLimit,
            'usage_percentage' => $percentage,
            'is_unlimited' => $usageLimit === -1,
            'overages' => $overages,
            'subscription' => $subscription,
        ]);
    }

    /**
     * Check if service should be restricted
     */
    public function checkServiceStatus(Request $request)
    {
        $subscription = Subscription::where('user_id', Auth::id())
            ->orderBy('created_at', 'desc')
            ->first();

        if (!$subscription) {
            return response()->json([
                'restricted' => true,
                'reason' => 'No active subscription',
            ]);
        }

        $shouldRestrict = $this->billingService->shouldRestrictService($subscription);
        $isInGracePeriod = $this->billingService->isInGracePeriod($subscription);

        return response()->json([
            'restricted' => $shouldRestrict,
            'in_grace_period' => $isInGracePeriod,
            'grace_period_ends_at' => $subscription->grace_period_ends_at,
            'reason' => $shouldRestrict ? 'Subscription expired or payment failed' : null,
        ]);
    }

    /**
     * Upgrade subscription (change plan)
     */
    public function upgradePlan(Request $request)
    {
        $request->validate([
            'package_id' => 'required|exists:packages,id',
            'billing_cycle' => 'required|in:monthly,yearly',
        ]);

        $user = Auth::user();
        $package = \App\Models\Package::find($request->package_id);

        // Calculate amount based on billing cycle
        $amount = $request->billing_cycle === 'yearly' 
            ? $package->yearly_price 
            : $package->monthly_price;

        // Create new subscription
        $subscription = Subscription::create([
            'user_id' => $user->id,
            'package_id' => $request->package_id,
            'status' => 'active',
            'billing_cycle' => $request->billing_cycle,
            'amount_paid' => $amount,
            'starts_at' => now(),
            'ends_at' => $request->billing_cycle === 'yearly' 
                ? now()->addYear() 
                : now()->addMonth(),
        ]);

        return response()->json(['success' => true, 'subscription' => $subscription]);
    }

    /**
     * Get billing history
     */
    public function getBillingHistory(Request $request)
    {
        $subscriptions = Subscription::where('user_id', Auth::id())
            ->with('package')
            ->orderBy('created_at', 'desc')
            ->get();

        $overages = Overage::where('user_id', Auth::id())
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'subscriptions' => $subscriptions,
            'overages' => $overages,
        ]);
    }
}
