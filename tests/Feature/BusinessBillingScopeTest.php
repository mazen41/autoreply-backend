<?php

namespace Tests\Feature;

use App\Models\BusinessProfile;
use App\Models\Package;
use App\Models\PaymentIntent;
use App\Models\Subscription;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BusinessBillingScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_current_subscription_and_usage_follow_the_selected_business(): void
    {
        $owner = User::factory()->create();
        $primary = BusinessProfile::factory()->create(['user_id' => $owner->id]);
        $secondary = BusinessProfile::factory()->create(['user_id' => $owner->id]);
        $owner->update(['business_id' => $primary->id]);
        $primaryPlan = $this->createPlan('Primary plan', 11);
        $secondaryPlan = $this->createPlan('Secondary plan', 22);
        $primarySubscription = $this->subscribe($owner, $primary, $primaryPlan);
        $this->subscribe($owner, $secondary, $secondaryPlan);

        $this->actingAs($owner)
            ->withHeader('X-Business-Id', (string) $secondary->id)
            ->getJson('/api/subscriptions/current')
            ->assertOk()
            ->assertJsonPath('business_id', $secondary->id)
            ->assertJsonPath('subscription.id', $secondary->subscription()->first()->id)
            ->assertJsonPath('package.name', 'Secondary plan');

        $this->withHeader('X-Business-Id', (string) $primary->id)
            ->getJson('/api/billing/subscription')
            ->assertOk()
            ->assertJsonPath('id', $primarySubscription->id)
            ->assertJsonPath('package.name', 'Primary plan');

        $outsider = User::factory()->create();
        $this->actingAs($outsider)
            ->withHeader('X-Business-Id', (string) $secondary->id)
            ->getJson('/api/subscriptions/current')
            ->assertForbidden();
    }

    public function test_billing_mutations_require_manage_billing_permission(): void
    {
        Http::fake();
        $owner = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $owner->id]);
        $owner->update(['business_id' => $business->id]);
        $plan = $this->createPlan('Paid plan', 50);
        $this->subscribe($owner, $business, $plan);

        $manager = User::factory()->create(['business_id' => null]);
        TeamMember::create([
            'business_id' => $business->id,
            'user_id' => $manager->id,
            'role' => 'manager',
            'is_active' => true,
        ]);

        $this->actingAs($manager)
            ->withHeader('X-Business-Id', (string) $business->id)
            ->getJson('/api/subscriptions/current')
            ->assertOk()
            ->assertJsonPath('package.name', 'Paid plan');

        $this->withHeader('X-Business-Id', (string) $business->id)
            ->deleteJson('/api/subscriptions')
            ->assertForbidden();

        $this->withHeader('X-Business-Id', (string) $business->id)
            ->postJson('/api/billing/upgrade', ['package_id' => $plan->id, 'billing_cycle' => 'monthly'])
            ->assertForbidden();

        $this->withHeader('X-Business-Id', (string) $business->id)
            ->postJson('/api/payment/myfatoorah/initiate', ['package_id' => $plan->id, 'billing_cycle' => 'monthly'])
            ->assertForbidden();
        $this->assertSame(0, PaymentIntent::count());
        Http::assertNothingSent();
    }

    public function test_paid_upgrade_cannot_activate_without_gateway_confirmation(): void
    {
        $owner = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $owner->id]);
        $owner->update(['business_id' => $business->id]);
        $current = $this->createPlan('Current plan', 50);
        $paid = Package::create([
            'name' => 'Paid plan', 'name_ar' => 'مدفوعة', 'price_monthly' => 100, 'price_yearly' => 1000,
            'ai_replies_limit' => 500, 'channels_limit' => 10, 'outbound_messages_limit' => 1000,
            'is_active' => true,
        ]);
        $this->subscribe($owner, $business, $current);

        $this->actingAs($owner)
            ->withHeader('X-Business-Id', (string) $business->id)
            ->postJson('/api/billing/upgrade', ['package_id' => $paid->id, 'billing_cycle' => 'monthly'])
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'PAYMENT_REQUIRED');

        $this->assertDatabaseCount('subscriptions', 1);
        $this->assertDatabaseHas('subscriptions', ['business_id' => $business->id, 'package_id' => $current->id, 'status' => 'active']);
    }

    private function createPlan(string $name, int $limit): Package
    {
        return Package::create([
            'name' => $name,
            'name_ar' => $name,
            'price_monthly' => 0,
            'price_yearly' => 0,
            'ai_replies_limit' => $limit,
            'channels_limit' => 4,
            'outbound_messages_limit' => $limit * 2,
            'is_active' => true,
        ]);
    }

    private function subscribe(User $user, BusinessProfile $business, Package $package): Subscription
    {
        return Subscription::create([
            'user_id' => $user->id,
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
