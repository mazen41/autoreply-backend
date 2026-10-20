<?php

namespace Tests\Feature;

use App\Models\BusinessProfile;
use App\Models\CommerceOrder;
use App\Models\Customer;
use App\Models\Segment;
use App\Models\User;
use App\Services\SegmentResolverService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerSegmentOrderRulesTest extends TestCase
{
    use RefreshDatabase;

    public function test_purchase_segments_use_synced_orders_not_checkout_drafts(): void
    {
        $owner = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $owner->id]);
        $draftOnly = Customer::create([
            'business_profile_id' => $business->id,
            'name' => 'Draft only',
            'email' => 'draft@example.test',
        ]);
        $purchaser = Customer::create([
            'business_profile_id' => $business->id,
            'name' => 'Real purchaser',
            'email' => 'buyer@example.test',
            'custom_fields' => ['lifecycle_stage' => 'customer'],
        ]);
        $segment = Segment::create([
            'business_profile_id' => $business->id,
            'name' => 'Recent purchasers',
            'rules' => ['min_orders' => 1, 'min_spent' => 50, 'last_order_days_ago' => 30, 'lifecycle_stage' => 'customer'],
        ]);

        CommerceOrder::create([
            'business_id' => $business->id,
            'channel_id' => $this->commerceChannel($owner, $business)->id,
            'external_id' => 'external-order-1',
            'order_number' => '1001',
            'status' => 'paid',
            'total' => 75,
            'currency' => 'SAR',
            'customer_email' => 'BUYER@example.test',
            'ordered_at' => now()->subDays(2),
        ]);
        $matching = SegmentResolverService::getMatchingCustomers($segment)->modelKeys();
        $this->assertSame([$purchaser->id], $matching);
        $this->assertNotContains($draftOnly->id, $matching);
    }

    private function commerceChannel(User $owner, BusinessProfile $business): \App\Models\Channel
    {
        return \App\Models\Channel::factory()->create([
            'user_id' => $owner->id,
            'business_id' => $business->id,
            'type' => 'shopify',
            'page_id' => 'segment-test-store',
            'status' => 'connected',
        ]);
    }
}
