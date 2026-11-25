<?php

namespace Tests;

use App\Models\BusinessProfile;
use App\Models\Package;
use App\Models\Subscription;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function activatePaidTestPlan(BusinessProfile $business, int $credits = 100000): void
    {
        $user = $business->user;
        $package = Package::query()->firstOrCreate(
            ['name' => 'Test Starter'],
            [
                'name_ar' => 'Test Starter',
                'price_monthly' => 0,
                'price_yearly' => 0,
                'ai_replies_limit' => -1,
                'ai_credits_monthly' => $credits,
                'channels_limit' => -1,
                'outbound_messages_limit' => -1,
                'max_bots' => -1,
                'max_team_members' => -1,
                'is_active' => true,
            ],
        );

        if ($user && (int) $user->business_id !== (int) $business->id) {
            $user->forceFill(['business_id' => $business->id])->save();
        }

        Subscription::query()->create([
            'user_id' => $business->user_id,
            'business_id' => $business->id,
            'package_id' => $package->id,
            'status' => 'active',
            'billing_cycle' => 'monthly',
            'amount_paid' => 0,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
        ]);
    }
}
