<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\BillingService;
use App\Models\Subscription;
use App\Models\Overage;
use App\Services\ActiveBusiness;
use App\Services\UsageMeter;
use Illuminate\Http\Request;

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
        $business = app(ActiveBusiness::class)->resolve($request, permission: 'read');
        $subscription = app(UsageMeter::class)->subscriptionFor($business);

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
        $business = app(ActiveBusiness::class)->resolve($request, permission: 'read');
        $snapshot = app(UsageMeter::class)->snapshot($business);
        $subscription = app(UsageMeter::class)->subscriptionFor($business);
        $subscriptionIds = Subscription::where('business_id', $business->id)->pluck('id');
        $overages = Overage::whereIn('subscription_id', $subscriptionIds)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($snapshot + [
            'usage_count' => $snapshot['usage']['ai_replies']['used'],
            'usage_limit' => $snapshot['usage']['ai_replies']['limit'],
            'usage_percentage' => $snapshot['usage']['ai_replies']['percentage'] ?? 0,
            'is_unlimited' => $snapshot['usage']['ai_replies']['unlimited'],
            'overages' => $overages,
            'subscription' => $subscription,
        ]);
    }

    public function usageForBusiness(Request $request, int $businessId)
    {
        $business = app(ActiveBusiness::class)->resolve(
            $request,
            permission: 'read',
            routeBusinessId: $businessId,
        );

        return response()->json(app(UsageMeter::class)->snapshot($business));
    }

    /**
     * Check if service should be restricted
     */
    public function checkServiceStatus(Request $request)
    {
        $business = app(ActiveBusiness::class)->resolve($request, permission: 'read');
        $subscription = app(UsageMeter::class)->subscriptionFor($business);

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
        $business = app(ActiveBusiness::class)->resolve($request, permission: 'manage_billing');
        $request->validate([
            'package_id' => 'required|exists:packages,id',
            'billing_cycle' => 'required|in:monthly,yearly',
        ]);

        $package = \App\Models\Package::query()->whereKey($request->package_id)->where('is_active', true)->firstOrFail();

        // Calculate amount based on billing cycle
        $amount = $request->billing_cycle === 'yearly'
            ? $package->price_yearly
            : $package->price_monthly;

        // This endpoint must never grant a paid plan without a verified
        // gateway callback. Paid upgrades go through the checkout endpoints.
        if ((float) $amount > 0) {
            return response()->json([
                'message' => 'Paid plan changes must be completed through checkout.',
                'error_code' => 'PAYMENT_REQUIRED',
                'package_id' => $package->id,
            ], 409);
        }

        // Create new subscription
        Subscription::query()
            ->where('business_id', $business->id)
            ->whereIn('status', ['active', 'trial'])
            ->update(['status' => 'cancelled', 'cancelled_at' => now()]);

        $subscription = Subscription::create([
            'user_id' => $business->user_id,
            'business_id' => $business->id,
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
        $business = app(ActiveBusiness::class)->resolve($request, permission: 'read');
        $subscriptions = Subscription::where('business_id', $business->id)
            ->with('package')
            ->orderBy('created_at', 'desc')
            ->get();

        $overages = Overage::whereIn('subscription_id', $subscriptions->pluck('id'))
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'subscriptions' => $subscriptions,
            'overages' => $overages,
        ]);
    }
}
