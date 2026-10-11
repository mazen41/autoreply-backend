<?php

namespace Tests\Feature;

use App\Models\BusinessProfile;
use App\Models\Channel;
use App\Models\Package;
use App\Models\Subscription;
use App\Models\User;
use App\Services\UsageMeter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UsageMeterChannelLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_commerce_stores_and_disconnected_channels_do_not_use_the_channel_allowance(): void
    {
        $user = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $user->id]);
        $package = Package::create(['name' => 'Starter', 'name_ar' => 'ستارتر', 'price_monthly' => 0, 'price_yearly' => 0, 'channels_limit' => 3]);
        Subscription::create([
            'user_id' => $user->id,
            'package_id' => $package->id,
            'status' => 'active',
            'amount_paid' => 0,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
        ]);

        Channel::factory()->create(['user_id' => $user->id, 'business_id' => $business->id, 'type' => 'facebook', 'status' => 'connected']);
        Channel::factory()->create(['user_id' => $user->id, 'business_id' => $business->id, 'type' => 'instagram', 'status' => 'connected']);
        foreach (['salla', 'shopify', 'woocommerce'] as $store) {
            Channel::factory()->create(['user_id' => $user->id, 'business_id' => $business->id, 'type' => $store, 'status' => 'connected']);
        }
        Channel::factory()->create(['user_id' => $user->id, 'business_id' => $business->id, 'type' => 'telegram', 'status' => 'disconnected']);

        $this->assertSame(2, app(UsageMeter::class)->canUse($business, UsageMeter::CHANNELS)['used']);
    }
}