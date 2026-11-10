<?php

namespace App\Services;

use App\Models\BusinessProfile;
use App\Models\Package;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SignupPlanService
{
    public function createWorkspace(User $user, string $choice, ?int $packageId = null, ?string $billingCycle = null): BusinessProfile
    {
        return DB::transaction(function () use ($user, $choice, $packageId, $billingCycle): BusinessProfile {
            if (BusinessProfile::where('user_id', $user->id)->exists()) {
                throw ValidationException::withMessages(['signup_choice' => 'This account already has a workspace.']);
            }

            if ($choice === 'trial') {
                $package = Package::query()->where('name', 'Business')->where('is_active', true)->first();
                if (!$package) {
                    throw ValidationException::withMessages(['signup_choice' => 'The Business trial is temporarily unavailable.']);
                }
            } else {
                $package = Package::query()->whereKey($packageId)->where('is_active', true)->first();
                if (!$package || ((float) $package->price_monthly <= 0 && (float) $package->price_yearly <= 0)) {
                    throw ValidationException::withMessages(['package_id' => 'Choose an available paid plan.']);
                }
                if (!in_array($billingCycle, ['monthly', 'yearly'], true)) {
                    throw ValidationException::withMessages(['billing_cycle' => 'Choose a billing cycle.']);
                }
            }

            $business = BusinessProfile::create([
                'user_id' => $user->id,
                'name' => $user->name . "'s Business",
                'auto_order_confirmation_enabled' => false,
            ]);

            $user->forceFill([
                'business_id' => $business->id,
                'signup_plan_choice' => $choice,
                'signup_package_id' => $package->id,
                'signup_billing_cycle' => $choice === 'trial' ? 'monthly' : $billingCycle,
            ])->save();

            if ($choice === 'trial') {
                $startsAt = now();
                $endsAt = $startsAt->copy()->addDays(14);

                Subscription::create([
                    'user_id' => $user->id,
                    'business_id' => $business->id,
                    'package_id' => $package->id,
                    'status' => 'trial',
                    'billing_cycle' => 'monthly',
                    'amount_paid' => 0,
                    'starts_at' => $startsAt,
                    'ends_at' => $endsAt,
                    'trial_ends_at' => $endsAt,
                ]);
            }

            return $business;
        });
    }
}
