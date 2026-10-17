<?php

namespace Tests\Feature;

use App\Jobs\SyncSallaCustomers as SyncSallaCustomersJob;
use App\Jobs\SyncSallaOrders as SyncSallaOrdersJob;
use App\Models\BusinessProfile;
use App\Models\Channel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SallaSyncCommandsTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_orders_succeeds_when_no_channels_exist(): void
    {
        $exitCode = Artisan::call('salla:sync-orders');
        $this->assertEquals(0, $exitCode);
    }

    public function test_sync_customers_succeeds_when_no_channels_exist(): void
    {
        $exitCode = Artisan::call('salla:sync-customers');
        $this->assertEquals(0, $exitCode);
    }

    public function test_sync_orders_dispatches_job_for_connected_salla_channel(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $business = BusinessProfile::create([
            'user_id' => $user->id,
            'business_name' => 'Test Business',
            'business_type' => 'retail',
        ]);

        $channel = Channel::create([
            'user_id' => $user->id,
            'business_id' => $business->id,
            'type' => 'salla',
            'status' => 'connected',
            'access_token' => 'test-salla-token',
        ]);

        $exitCode = Artisan::call('salla:sync-orders');
        $this->assertEquals(0, $exitCode);

        Queue::assertPushed(SyncSallaOrdersJob::class, function ($job) use ($channel) {
            return $job->channelId === $channel->id;
        });
    }

    public function test_sync_customers_dispatches_job_for_connected_salla_channel(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $business = BusinessProfile::create([
            'user_id' => $user->id,
            'business_name' => 'Test Business',
            'business_type' => 'retail',
        ]);

        $channel = Channel::create([
            'user_id' => $user->id,
            'business_id' => $business->id,
            'type' => 'salla',
            'status' => 'connected',
            'access_token' => 'test-salla-token',
        ]);

        $exitCode = Artisan::call('salla:sync-customers');
        $this->assertEquals(0, $exitCode);

        Queue::assertPushed(SyncSallaCustomersJob::class, function ($job) use ($channel) {
            return $job->channelId === $channel->id;
        });
    }
}
