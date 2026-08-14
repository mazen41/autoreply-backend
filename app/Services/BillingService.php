<?php

namespace App\Services;

use App\Models\Subscription;
use App\Models\Overage;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class BillingService
{
    /**
     * Check usage limits and send alerts
     */
    public function checkUsageLimits(): void
    {
        $subscriptions = Subscription::active()->get();

        foreach ($subscriptions as $subscription) {
            $package = $subscription->package;
            $usageLimit = $package->ai_replies_limit ?? 0;
            
            if ($usageLimit === -1) {
                // Unlimited plan
                continue;
            }

            $usageCount = $subscription->usage_count ?? 0;
            $percentage = $usageLimit > 0 ? ($usageCount / $usageLimit) * 100 : 0;

            // Check 80% threshold
            if ($percentage >= 80 && $percentage < 100) {
                $this->sendUsageAlert($subscription, $percentage);
            }

            // Check 100% threshold
            if ($percentage >= 100) {
                $this->sendUsageAlert($subscription, 100);
                $this->trackOverage($subscription);
            }
        }
    }

    /**
     * Send usage alert to user
     */
    private function sendUsageAlert(Subscription $subscription, float $percentage): void
    {
        $user = $subscription->user;
        $package = $subscription->package;

        // Check if we already sent an alert recently (avoid spam)
        $lastAlert = $subscription->last_usage_alert_at;
        if ($lastAlert && $lastAlert->diffInHours() < 24) {
            return;
        }

        try {
            Mail::raw(
                "Your AI usage is at {$percentage}% of your {$package->name} plan limit.\n\n" .
                "Used: {$subscription->usage_count} / {$usageLimit}\n\n" .
                "Please consider upgrading your plan to continue uninterrupted service.",
                function ($message) use ($user) {
                    $message->to($user->email)
                        ->subject('AI Usage Alert - Naz Platform');
                }
            );

            $subscription->update(['last_usage_alert_at' => now()]);

            Log::info('Usage alert sent', [
                'user_id' => $user->id,
                'percentage' => $percentage,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to send usage alert', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Track overage for billing
     */
    private function trackOverage(Subscription $subscription): void
    {
        $package = $subscription->package;
        $usageLimit = $package->ai_replies_limit ?? 0;
        $extraUsage = ($subscription->usage_count ?? 0) - $usageLimit;

        if ($extraUsage <= 0) {
            return;
        }

        // Calculate overage amount (assuming $0.01 per extra message)
        $amount = $extraUsage * 0.01;

        Overage::create([
            'user_id' => $subscription->user_id,
            'subscription_id' => $subscription->id,
            'extra_messages' => $extraUsage,
            'amount' => $amount,
            'status' => 'pending',
        ]);

        Log::info('Overage tracked', [
            'subscription_id' => $subscription->id,
            'extra_messages' => $extraUsage,
            'amount' => $amount,
        ]);
    }

    /**
     * Start grace period for failed payment
     */
    public function startGracePeriod(Subscription $subscription, int $days = 7): void
    {
        $gracePeriodEndsAt = now()->addDays($days);
        
        $subscription->update([
            'grace_period_ends_at' => $gracePeriodEndsAt,
        ]);

        try {
            $user = $subscription->user;
            Mail::raw(
                "Your payment has failed. You have {$days} days of grace period to update your payment method.\n\n" .
                "Service will continue until " . $gracePeriodEndsAt->toDateString() . ".\n\n" .
                "Please update your payment to avoid service interruption.",
                function ($message) use ($user) {
                    $message->to($user->email)
                        ->subject('Payment Failed - Grace Period Started');
                }
            );

            Log::info('Grace period started', [
                'subscription_id' => $subscription->id,
                'ends_at' => $gracePeriodEndsAt,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to send grace period notification', [
                'subscription_id' => $subscription->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Check if subscription is in grace period
     */
    public function isInGracePeriod(Subscription $subscription): bool
    {
        if (!$subscription->grace_period_ends_at) {
            return false;
        }

        return now()->lt($subscription->grace_period_ends_at);
    }

    /**
     * Check if service should be restricted
     */
    public function shouldRestrictService(Subscription $subscription): bool
    {
        // Restrict if:
        // 1. Subscription is expired
        // 2. Grace period has ended
        // 3. Status is cancelled

        if ($subscription->status === 'cancelled') {
            return true;
        }

        if ($subscription->isExpired()) {
            return true;
        }

        if ($subscription->grace_period_ends_at && now()->gt($subscription->grace_period_ends_at)) {
            return true;
        }

        return false;
    }

    /**
     * Bill pending overages
     */
    public function billPendingOverages(): void
    {
        $pendingOverages = Overage::pending()->get();

        foreach ($pendingOverages as $overage) {
            try {
                // TODO: implement Paymob recurring charge for overage billing
                Log::info('Overage billing skipped (Paymob integration pending)', [
                    'overage_id' => $overage->id,
                    'amount'     => $overage->amount,
                ]);
            } catch (\Exception $e) {
                $overage->update(['status' => 'failed']);

                Log::error('Overage billing exception', [
                    'overage_id' => $overage->id,
                    'error'      => $e->getMessage(),
                ]);
            }
        }
    }
}
