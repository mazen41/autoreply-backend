<?php

namespace App\Services;

use App\Models\Subscription;
use Illuminate\Support\Facades\Log;

class SubscriptionLifecycleService
{
    /** Stop replaced plans and their provider renewal before activating another plan. */
    public function retireActiveForBusiness(int $businessId): void
    {
        $subscriptions = Subscription::query()
            ->where('business_id', $businessId)
            ->whereIn('status', ['active', 'trial'])
            ->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->get();

        foreach ($subscriptions as $subscription) {
            if ($subscription->auto_renew_enabled
                && $subscription->payment_gateway === 'myfatoorah'
                && $subscription->myfatoorah_recurring_id) {
                try {
                    app(MyFatoorahService::class)->cancelRecurringPayment($subscription->myfatoorah_recurring_id);
                } catch (\Throwable $exception) {
                    // The new paid plan must still be honored, but this needs
                    // manual provider follow-up to prevent a duplicate charge.
                    Log::critical('Could not stop replaced MyFatoorah renewal', [
                        'business_id' => $businessId,
                        'subscription_id' => $subscription->id,
                        'exception_type' => get_class($exception),
                    ]);
                }
            }

            $subscription->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'auto_renew_enabled' => false,
            ]);
        }
    }
}
