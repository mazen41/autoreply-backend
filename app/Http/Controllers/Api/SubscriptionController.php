<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Package;
use App\Models\Subscription;
use App\Services\ActiveBusiness;
use App\Services\UsageMeter;
use App\Services\MyFatoorahService;
use Illuminate\Http\Request;

class SubscriptionController extends Controller
{
    public function current(Request $request, ActiveBusiness $activeBusiness, UsageMeter $usageMeter)
    {
        $business = $activeBusiness->resolve($request, permission: 'read');
        $subscription = $usageMeter->subscriptionFor($business);

        if (!$subscription) {
            // Return Free package as default
            $freePackage = Package::where('name', 'Free')->first();
            return response()->json([
                'subscription' => null,
                'package' => $freePackage,
                'is_free' => true,
                'business_id' => $business->id,
            ]);
        }

        return response()->json([
            'subscription' => $subscription->load('package'),
            'package' => $subscription->package,
            'is_free' => false,
            'business_id' => $business->id,
        ]);
    }

    public function cancel(Request $request, ActiveBusiness $activeBusiness, UsageMeter $usageMeter, MyFatoorahService $myFatoorah)
    {
        $business = $activeBusiness->resolve($request, permission: 'manage_billing');
        $subscription = $usageMeter->subscriptionFor($business);

        if (!$subscription) {
            return response()->json(['message' => 'No active subscription found'], 404);
        }

        if ($subscription->auto_renew_enabled && $subscription->myfatoorah_recurring_id) {
            try {
                $myFatoorah->cancelRecurringPayment($subscription->myfatoorah_recurring_id);
            } catch (\Throwable $exception) {
                \Illuminate\Support\Facades\Log::warning('Could not cancel recurring payment with subscription cancellation', [
                    'subscription_id' => $subscription->id,
                    'exception_type' => get_class($exception),
                ]);
                return response()->json(['message' => 'Automatic renewal could not be stopped, so the subscription was not cancelled. Please try again or contact support.'], 502);
            }
        }

        $subscription->update([
            'status' => 'cancelled',
            'auto_renew_enabled' => false,
            'cancelled_at' => now()
        ]);

        return response()->json(['message' => 'Subscription cancelled successfully']);
    }

    public function disableAutoRenew(Request $request, ActiveBusiness $activeBusiness, UsageMeter $usageMeter, MyFatoorahService $myFatoorah)
    {
        $business = $activeBusiness->resolve($request, permission: 'manage_billing');
        $subscription = $usageMeter->subscriptionFor($business);

        if (!$subscription || !$subscription->auto_renew_enabled || !$subscription->myfatoorah_recurring_id) {
            return response()->json(['message' => 'This business has no active automatic renewal to turn off.'], 404);
        }

        try {
            $myFatoorah->cancelRecurringPayment($subscription->myfatoorah_recurring_id);
        } catch (\Throwable $exception) {
            \Illuminate\Support\Facades\Log::warning('Could not cancel MyFatoorah recurring payment', [
                'subscription_id' => $subscription->id,
                'exception_type' => get_class($exception),
            ]);
            return response()->json(['message' => 'Automatic renewal could not be cancelled. Please try again or contact support.'], 502);
        }

        $subscription->update(['auto_renew_enabled' => false]);

        return response()->json(['message' => 'Automatic renewal is off. Your current plan remains active until its end date.']);
    }

    public function createFree(Request $request, ActiveBusiness $activeBusiness, UsageMeter $usageMeter)
    {
        $user = auth()->user();
        $business = $activeBusiness->resolve($request, permission: 'manage_billing');
        
        // A business can have only one active plan. Another business owned by
        // the same user may keep its own independent subscription.
        if ($usageMeter->subscriptionFor($business)) {
            return response()->json(['message' => 'This business already has an active subscription'], 400);
        }

        $validated = $request->validate([
            'package_id' => 'required|exists:packages,id',
            'billing_cycle' => 'required|in:monthly,yearly',
        ]);

        $package = Package::query()->whereKey($validated['package_id'])->where('is_active', true)->firstOrFail();

        // Only allow free packages
        if ($package->price_monthly > 0 || $package->price_yearly > 0) {
            return response()->json(['message' => 'This endpoint is only for free plans'], 400);
        }

        $subscription = Subscription::create([
            'user_id' => $user->id,
            'business_id' => $business->id,
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
