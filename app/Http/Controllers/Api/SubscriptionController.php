<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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
            $trialExpired = \App\Models\Subscription::query()
                ->where('business_id', $business->id)
                ->whereNotNull('trial_ends_at')
                ->where(function ($query): void {
                    $query->where('ends_at', '<=', now())->orWhere('trial_ends_at', '<=', now());
                })->exists();
            return response()->json([
                'subscription' => null,
                'package' => null,
                'is_free' => false,
                'requires_plan' => true,
                'trial_expired' => $trialExpired,
                'business_id' => $business->id,
            ]);
        }

        return response()->json([
            'subscription' => $subscription->load('package'),
            'package' => $subscription->package,
            'is_free' => false,
            'requires_plan' => false,
            'trial_expired' => false,
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
        return response()->json(['message' => 'There is no free plan. Choose the 14-day Business trial during signup or purchase a paid plan.'], 410);
    }
}
