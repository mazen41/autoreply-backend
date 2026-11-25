<?php

namespace Tests\Feature;

use App\Jobs\SyncSallaProducts;
use App\Models\BusinessProfile;
use App\Models\Channel;
use App\Models\Product;
use App\Models\User;
use App\Services\SallaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class SallaProductSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_queue_sync_for_connected_salla_channel(): void
    {
        Queue::fake();
        [$user, $channel] = $this->makeStore();
        Sanctum::actingAs($user);

        $this->postJson("/api/channels/salla/{$channel->id}/sync")
            ->assertOk()->assertJsonPath('status', 'queued');

        Queue::assertPushed(SyncSallaProducts::class, fn ($job) => $job->channelId === $channel->id);
        $this->assertSame('queued', $channel->fresh()->metadata['sync_status']);
    }

    public function test_sync_job_saves_products_by_channel_and_marks_completion(): void
    {
        [, $channel] = $this->makeStore();
        $service = Mockery::mock(SallaService::class);
        $service->shouldReceive('getProductsForChannel')->once()->withArgs(
            fn (Channel $resolved, array $params) => $resolved->id === $channel->id
                && $params === ['page' => 1, 'per_page' => 15]
        )
            ->andReturn(['data' => [[
                'id' => 9101, 'name' => 'Summer dress', 'description' => '<p>Dress</p>',
                'sku' => 'D-1', 'price' => ['amount' => '149.00', 'currency_code' => 'SAR'],
                'quantity' => 4, 'images' => [['url' => 'https://example.test/dress.jpg']],
            ]], 'pagination' => ['totalPages' => 1]]);
        $service->shouldReceive('extractImageUrl')->once()->andReturn('https://example.test/dress.jpg');

        (new SyncSallaProducts($channel->id))->handle($service);

        $product = Product::query()->where('commerce_channel_id', $channel->id)->firstOrFail();
        $this->assertSame('9101', $product->commerce_external_id);
        $this->assertSame((int) $channel->business_id, (int) $product->business_id);
        $this->assertSame(149.0, (float) $product->price);
        $this->assertSame('https://example.test/dress.jpg', $product->metadata['image_url']);
        $this->assertSame('completed', $channel->fresh()->metadata['sync_status']);
        $this->assertSame(1, $channel->fresh()->metadata['products_synced']);
        $this->assertNotEmpty($channel->fresh()->metadata['last_synced_at']);
    }

    private function makeStore(): array
    {
        $user = User::factory()->create();
        $business = BusinessProfile::create(['user_id' => $user->id, 'name' => 'Test business']);
        $user->update(['business_id' => $business->id]);
        $channel = Channel::factory()->create([
            'user_id' => $user->id,
            'business_id' => $business->id,
            'type' => 'salla',
            'status' => 'connected',
            'access_token' => 'test-token',
            'metadata' => [],
        ]);

        return [$user, $channel];
    }
}
