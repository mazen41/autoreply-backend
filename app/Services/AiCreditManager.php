<?php

namespace App\Services;

use App\Exceptions\InsufficientAiCreditsException;
use App\Models\AiCreditPackage;
use App\Models\AiCreditTransaction;
use App\Models\AiCreditWallet;
use App\Models\AiModelPrice;
use App\Models\AiUsageLog;
use App\Models\BusinessProfile;
use App\Models\Notification;
use App\Models\Package;
use App\Models\Subscription;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AiCreditManager
{
    public function settings(): object
    {
        $settings = DB::table('ai_credit_settings')->first()
            ?? (object) ['credits_per_usd' => 10000, 'cost_multiplier' => '2.0000', 'reservation_minimum' => 10, 'reservation_maximum' => 5000, 'notification_thresholds' => [50, 80, 90, 100], 'enabled_modes' => ['auto', 'economy', 'premium'], 'system_enabled' => true];
        foreach (['notification_thresholds', 'enabled_modes'] as $field) {
            if (is_string($settings->{$field} ?? null)) $settings->{$field} = json_decode($settings->{$field}, true) ?: [];
        }
        return $settings;
    }

    public function walletFor(BusinessProfile $business): AiCreditWallet
    {
        $subscription = app(UsageMeter::class)->subscriptionFor($business);
        $package = $subscription?->package ?? Package::query()->where('name', 'Free')->first();
        $monthlyAllowance = (int) ($package?->ai_credits_monthly ?? $package?->ai_replies_limit ?? 0);
        [$periodStart, $periodEnd] = $this->billingPeriod($subscription);
        $periodKey = ($subscription?->id ?? 'free') . ':' . $periodStart->format('Y-m');

        return DB::transaction(function () use ($business, $subscription, $monthlyAllowance, $periodStart, $periodEnd, $periodKey): AiCreditWallet {
            AiCreditWallet::query()->firstOrCreate(['business_id' => $business->id]);
            $wallet = AiCreditWallet::query()->where('business_id', $business->id)->lockForUpdate()->firstOrFail();
            if ($wallet->billing_period_key !== $periodKey) {
                $before = (int) $wallet->subscription_balance + (int) $wallet->purchased_balance;
                $expired = (int) $wallet->subscription_balance;
                $wallet->subscription_balance = $monthlyAllowance;
                $wallet->billing_period_key = $periodKey;
                $wallet->billing_period_starts_at = $periodStart;
                $wallet->billing_period_ends_at = $periodEnd;
                $wallet->notified_thresholds = [];
                $wallet->save();

                if ($expired > 0) {
                    $this->recordTransaction($wallet, null, 'expiration', 'subscription', $expired, $before, (int) $wallet->purchased_balance, 'Unused monthly AI credits expired at billing-cycle reset.', ['subscription_id' => $subscription?->id, 'period_key' => $periodKey]);
                }
                if ($monthlyAllowance > 0) {
                    $this->recordTransaction($wallet, null, 'credit', 'subscription', $monthlyAllowance, (int) $wallet->purchased_balance, (int) $wallet->purchased_balance + $monthlyAllowance, 'Monthly AI credits granted.', ['subscription_id' => $subscription?->id, 'period_key' => $periodKey]);
                }
            }

            return $wallet->fresh();
        });
    }

    public function balance(BusinessProfile $business): array
    {
        $wallet = $this->walletFor($business);
        $available = max(0, (int) $wallet->subscription_balance + (int) $wallet->purchased_balance - (int) $wallet->reserved_balance);
        $subscription = app(UsageMeter::class)->subscriptionFor($business);
        $allowance = (int) ($subscription?->package?->ai_credits_monthly ?? Package::where('name', 'Free')->value('ai_credits_monthly') ?? 0);
        $consumedThisCycle = (int) AiUsageLog::query()->where('business_id', $business->id)->where('status', 'succeeded')->where('created_at', '>=', $wallet->billing_period_starts_at ?? now()->startOfMonth())->sum('credits_consumed');

        return [
            'business_id' => (int) $business->id,
            'subscription' => ['plan' => $subscription?->package?->name ?? 'Free', 'allowance' => $allowance, 'renewal_at' => $subscription?->ends_at?->toISOString()],
            'wallet' => ['subscription_balance' => (int) $wallet->subscription_balance, 'purchased_balance' => (int) $wallet->purchased_balance, 'reserved_balance' => (int) $wallet->reserved_balance, 'available_balance' => $available, 'total_consumed' => (int) $wallet->total_consumed, 'period_ends_at' => $wallet->billing_period_ends_at?->toISOString()],
            'cycle_usage' => ['credits_consumed' => $consumedThisCycle, 'allowance' => $allowance, 'percentage' => $allowance > 0 ? min(100, round($consumedThisCycle * 100 / $allowance, 2)) : null],
        ];
    }

    public function reserve(BusinessProfile $business, AiModelPrice $price, int $inputTokens, int $maxOutputTokens, string $operationId, string $task, int $estimatedAudioSeconds = 0, int $estimatedImages = 0, int $estimatedAudioOutputChars = 0): int
    {
        $settings = $this->settings();
        if (!(bool) $settings->system_enabled) {
            throw new \RuntimeException('AI credit billing is temporarily unavailable.');
        }
        $expected = $this->calculateCredits($price, $inputTokens, $maxOutputTokens, $settings, 0, $estimatedAudioSeconds, $estimatedImages, $estimatedAudioOutputChars);
        $reservation = max((int) $settings->reservation_minimum, (int) ceil($expected * 1.25));
        if ($reservation > (int) $settings->reservation_maximum) {
            throw new \RuntimeException('This AI request exceeds the configured per-operation credit reservation limit.');
        }

        DB::transaction(function () use ($business, $operationId, $reservation, $task): void {
            $wallet = AiCreditWallet::query()->where('business_id', $business->id)->lockForUpdate()->firstOrFail();
            $available = (int) $wallet->subscription_balance + (int) $wallet->purchased_balance - (int) $wallet->reserved_balance;
            if ($available < $reservation) {
                throw new InsufficientAiCreditsException(['available' => max(0, $available), 'required' => $reservation]);
            }
            $wallet->reserved_balance = (int) $wallet->reserved_balance + $reservation;
            $wallet->save();
            $this->recordTransaction($wallet, $operationId, 'reservation', 'mixed', $reservation, $available, $available - $reservation, "Reserved AI credits for {$task}.", ['task_type' => $task]);
        });

        return $reservation;
    }

    public function settle(BusinessProfile $business, AiUsageLog $usage, int $reserved, int $inputTokens, int $outputTokens, int $cachedTokens = 0, int $audioSeconds = 0, int $imageUnits = 0, int $audioOutputChars = 0): int
    {
        $price = AiModelPrice::query()->where('provider', $usage->provider)->where('model_key', $usage->model_key)->firstOrFail();
        $settings = $this->settings();
        $costMicros = $this->costMicros($price, $inputTokens, $outputTokens, $cachedTokens, $audioSeconds, $imageUnits, $audioOutputChars);
        $credits = $this->creditsForCostMicros($costMicros, $settings);
        $periodKey = $this->walletFor($business)->billing_period_key;

        DB::transaction(function () use ($business, $usage, $reserved, $inputTokens, $outputTokens, $cachedTokens, $costMicros, $credits, $price, $periodKey, $audioSeconds, $imageUnits, $audioOutputChars): void {
            $lockedUsage = AiUsageLog::query()->lockForUpdate()->findOrFail($usage->id);
            if ($lockedUsage->status === 'succeeded') {
                return;
            }
            $wallet = AiCreditWallet::query()->where('business_id', $business->id)->lockForUpdate()->firstOrFail();
            $wallet->reserved_balance = max(0, (int) $wallet->reserved_balance - $reserved);
            $totalBalance = (int) $wallet->subscription_balance + (int) $wallet->purchased_balance;
            $charge = min($credits, $totalBalance);
            $fromSubscription = min((int) $wallet->subscription_balance, $charge);
            $fromPurchased = $charge - $fromSubscription;
            $before = $totalBalance;
            $wallet->subscription_balance = (int) $wallet->subscription_balance - $fromSubscription;
            $wallet->purchased_balance = (int) $wallet->purchased_balance - $fromPurchased;
            $wallet->total_consumed = (int) $wallet->total_consumed + $credits;
            $wallet->save();

            if ($charge > 0) {
                $this->recordTransaction($wallet, $usage->operation_id, 'debit', 'mixed', $charge, $before, $before - $charge, "AI usage: {$usage->task_type}.", ['usage_log_id' => $usage->id, 'credits_calculated' => $credits, 'underfunded_credits' => max(0, $credits - $charge)]);
            }
            if ($reserved > $credits) {
                $availableAfter = (int) $wallet->subscription_balance + (int) $wallet->purchased_balance - (int) $wallet->reserved_balance;
                $this->recordTransaction($wallet, $usage->operation_id, 'release', 'reservation', $reserved - $credits, max(0, $availableAfter - ($reserved - $credits)), $availableAfter, 'Unused AI credit reservation released.');
            }

            $lockedUsage->update([
                'input_tokens' => $inputTokens,
                'output_tokens' => $outputTokens,
                'cached_tokens' => $cachedTokens,
                'provider_cost_usd' => $this->formatMicroUsd($costMicros),
                'credits_consumed' => $credits,
                'pricing_version' => $price->pricing_version,
                'status' => 'succeeded',
                'metadata' => array_merge($lockedUsage->metadata ?? [], [
                    'audio_seconds' => $audioSeconds,
                    'image_units' => $imageUnits,
                    'audio_output_chars' => $audioOutputChars,
                    'underfunded_credits' => max(0, $credits - $charge),
                    // Preserve the exact conversion inputs for audit; later admin changes
                    // must never alter the economics recorded for historical operations.
                    'credits_per_usd' => (int) ($settings->credits_per_usd ?? 10000),
                    'cost_multiplier' => (string) ($settings->cost_multiplier ?? '2.0000'),
                ]),
            ]);
            $this->notifyThresholds($business, $wallet, $periodKey);
        });

        return $credits;
    }

    public function markUncertainFailure(AiUsageLog $usage, int $reserved, \Throwable $exception): void
    {
        DB::transaction(function () use ($usage, $reserved, $exception): void {
            $locked = AiUsageLog::query()->lockForUpdate()->find($usage->id);
            if (!$locked || $locked->status !== 'pending') {
                return;
            }
            // The provider may have processed/billed a request before the transport failed.
            // Keep the reservation quarantined for admin reconciliation instead of assuming zero cost.
            $locked->update(['status' => 'needs_reconciliation', 'metadata' => array_merge($locked->metadata ?? [], ['failure_type' => get_class($exception), 'reservation_quarantined' => $reserved])]);
        });
    }

    public function releaseFailedReservation(AiUsageLog $usage, int $reserved, \Throwable $exception): void
    {
        DB::transaction(function () use ($usage, $reserved, $exception): void {
            $locked = AiUsageLog::query()->lockForUpdate()->find($usage->id);
            if (!$locked || !in_array($locked->status, ['pending', 'needs_reconciliation'], true)) return;
            $wallet = AiCreditWallet::query()->where('business_id', $locked->business_id)->lockForUpdate()->firstOrFail();
            $before = (int) $wallet->subscription_balance + (int) $wallet->purchased_balance - (int) $wallet->reserved_balance;
            $wallet->reserved_balance = max(0, (int) $wallet->reserved_balance - $reserved);
            $wallet->save();
            $after = (int) $wallet->subscription_balance + (int) $wallet->purchased_balance - (int) $wallet->reserved_balance;
            $this->recordTransaction($wallet, $locked->operation_id, 'release', 'reservation', $reserved, max(0, $before), max(0, $after), 'Released reservation after provider rejected the request.', ['usage_log_id' => $locked->id]);
            $locked->update(['status' => 'failed', 'metadata' => array_merge($locked->metadata ?? [], ['failure_type' => get_class($exception), 'http_status' => $exception->getCode(), 'reservation_released' => $reserved])]);
        });
    }

    public function addPurchasedCredits(BusinessProfile $business, int $credits, string $operationId, array $metadata = []): void
    {
        DB::transaction(function () use ($business, $credits, $operationId, $metadata): void {
            if (AiCreditTransaction::query()->where('operation_id', $operationId)->where('type', 'credit')->exists()) {
                return;
            }
            $wallet = $this->walletFor($business);
            $wallet = AiCreditWallet::query()->whereKey($wallet->id)->lockForUpdate()->firstOrFail();
            $before = (int) $wallet->purchased_balance;
            $wallet->purchased_balance = $before + $credits;
            $wallet->save();
            $this->recordTransaction($wallet, $operationId, 'credit', 'purchased', $credits, $before, (int) $wallet->purchased_balance, 'Purchased AI credits added after verified payment.', $metadata);
        });
    }

    public function adjust(BusinessProfile $business, int $amount, string $reason, int $adminUserId): void
    {
        DB::transaction(function () use ($business, $amount, $reason, $adminUserId): void {
            $wallet = $this->walletFor($business);
            $wallet = AiCreditWallet::query()->whereKey($wallet->id)->lockForUpdate()->firstOrFail();
            $before = (int) $wallet->purchased_balance;
            if ($amount < 0 && $before < abs($amount)) {
                throw new \InvalidArgumentException('Adjustment would make the purchased credit balance negative.');
            }
            $wallet->purchased_balance = $before + $amount;
            $wallet->save();
            $this->recordTransaction($wallet, (string) Str::uuid(), 'adjustment', 'purchased', abs($amount), $before, (int) $wallet->purchased_balance, $reason, ['admin_user_id' => $adminUserId, 'direction' => $amount >= 0 ? 'credit' : 'debit']);
        });
    }

    public function calculateCredits(AiModelPrice $price, int $inputTokens, int $outputTokens, object $settings, int $cachedTokens = 0, int $audioSeconds = 0, int $imageUnits = 0, int $audioOutputChars = 0): int
    {
        return $this->creditsForCostMicros($this->costMicros($price, $inputTokens, $outputTokens, $cachedTokens, $audioSeconds, $imageUnits, $audioOutputChars), $settings);
    }

    private function costMicros(AiModelPrice $price, int $inputTokens, int $outputTokens, int $cachedTokens, int $audioSeconds = 0, int $imageUnits = 0, int $audioOutputChars = 0): int
    {
        $inputPrice = $this->scaledDecimal($price->input_price_per_million, 6);
        $outputPrice = $this->scaledDecimal($price->output_price_per_million, 6);
        $cachedPrice = $price->cached_input_price_per_million !== null ? $this->scaledDecimal($price->cached_input_price_per_million, 6) : $inputPrice;
        $cachedTokens = min($cachedTokens, $inputTokens);
        $regularInputTokens = $inputTokens - $cachedTokens;
        // Price is micro-USD per million tokens; result is rounded up to micro-USD.
        $tokenCost = intdiv($regularInputTokens * $inputPrice + 999999, 1000000)
            + intdiv($cachedTokens * $cachedPrice + 999999, 1000000)
            + intdiv($outputTokens * $outputPrice + 999999, 1000000);
        $audioRate = $price->audio_price_per_minute !== null ? $this->scaledDecimal($price->audio_price_per_minute, 6) : 0;
        $audioCost = $audioSeconds > 0 ? intdiv($audioRate * $audioSeconds + 59, 60) : 0;
        $imageRate = $price->image_price_per_unit !== null ? $this->scaledDecimal($price->image_price_per_unit, 6) : 0;
        $audioOutputRate = $price->audio_output_price_per_million_chars !== null ? $this->scaledDecimal($price->audio_output_price_per_million_chars, 6) : 0;
        $audioOutputCost = $audioOutputChars > 0 ? intdiv($audioOutputChars * $audioOutputRate + 999999, 1000000) : 0;
        return $tokenCost + $audioCost + ($imageUnits > 0 ? $imageRate * $imageUnits : 0) + $audioOutputCost;
    }

    private function creditsForCostMicros(int $costMicros, object $settings): int
    {
        $multiplier = $this->scaledDecimal($settings->cost_multiplier ?? '2', 4);
        $numerator = $costMicros * (int) ($settings->credits_per_usd ?? 10000) * $multiplier;
        return $numerator > 0 ? intdiv($numerator + 9999999999, 10000000000) : 0;
    }

    private function scaledDecimal($value, int $scale): int
    {
        $parts = explode('.', (string) $value, 2);
        $whole = (int) ($parts[0] ?: 0);
        $fraction = str_pad(substr($parts[1] ?? '', 0, $scale), $scale, '0');
        return $whole * (10 ** $scale) + (int) $fraction;
    }

    private function formatMicroUsd(int $micros): string
    {
        return intdiv($micros, 1000000) . '.' . str_pad((string) ($micros % 1000000), 6, '0', STR_PAD_LEFT);
    }

    private function recordTransaction(AiCreditWallet $wallet, ?string $operationId, string $type, string $source, int $amount, int $before, int $after, string $description, array $metadata = []): void
    {
        AiCreditTransaction::query()->create(['wallet_id' => $wallet->id, 'business_id' => $wallet->business_id, 'operation_id' => $operationId, 'type' => $type, 'source' => $source, 'amount' => max(0, $amount), 'balance_before' => max(0, $before), 'balance_after' => max(0, $after), 'description' => $description, 'metadata' => $metadata, 'created_at' => now()]);
    }

    private function notifyThresholds(BusinessProfile $business, AiCreditWallet $wallet, ?string $periodKey): void
    {
        $subscription = app(UsageMeter::class)->subscriptionFor($business);
        $allowance = (int) ($subscription?->package?->ai_credits_monthly ?? Package::where('name', 'Free')->value('ai_credits_monthly') ?? 0);
        if ($allowance <= 0 || !$business->user_id) return;
        $settings = $this->settings();
        $businessSettings = AiBusinessSetting::query()->where('business_id', $business->id)->first();
        $thresholds = $businessSettings?->notification_thresholds ?: $settings->notification_thresholds;
        if (is_string($thresholds)) $thresholds = json_decode($thresholds, true);
        $thresholds = is_array($thresholds) ? $thresholds : [50, 80, 90, 100];
        $consumed = (int) AiUsageLog::query()->where('business_id', $business->id)->where('status', 'succeeded')->where('created_at', '>=', $wallet->billing_period_starts_at ?? now()->startOfMonth())->sum('credits_consumed');
        $percentage = $consumed * 100 / $allowance;
        $notified = $wallet->notified_thresholds ?? [];
        foreach ($thresholds as $threshold) {
            $threshold = (int) $threshold;
            $key = ($periodKey ?? '') . ':' . $threshold;
            if ($percentage < $threshold || in_array($key, $notified, true)) continue;
            Notification::query()->create(['user_id' => $business->user_id, 'type' => 'ai_credits', 'title' => "AI credits {$threshold}% used", 'message' => $threshold >= 100 ? 'Your monthly AI allowance is exhausted. AI can continue using any purchased balance; buy more credits or wait for renewal when the available balance reaches zero.' : "You have used {$threshold}% of your monthly AI credit allowance.", 'data' => ['business_id' => $business->id, 'threshold' => $threshold, 'period_key' => $periodKey, 'consumed' => $consumed, 'allowance' => $allowance], 'action_url' => '/dashboard/billing', 'is_read' => false]);
            $notified[] = $key;
        }
        $wallet->update(['notified_thresholds' => array_values(array_unique($notified))]);
    }

    /** Keep allowances aligned to the active subscription term, not the calendar month. */
    private function billingPeriod(?Subscription $subscription): array
    {
        if (!$subscription?->starts_at || !$subscription?->ends_at) {
            return [now()->startOfMonth(), now()->endOfMonth()];
        }

        $start = $subscription->starts_at->copy();
        $end = $start->copy()->addMonthNoOverflow();
        $safety = 0;
        while ($end->lte(now()) && $safety++ < 240) {
            $start = $end;
            $end = $start->copy()->addMonthNoOverflow();
        }
        $subscriptionEnd = $subscription->ends_at->copy();
        return [$start, $end->lt($subscriptionEnd) ? $end : $subscriptionEnd];
    }
}
