<?php

namespace Tests\Feature;

use App\Models\BusinessProfile;
use App\Models\Channel;
use App\Models\User;
use App\Services\CommerceStoreSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShopifyProtectedCustomerDataSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_data_approval_error_does_not_block_products_and_orders_sync(): void
    {
        $user = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $user->id]);
        $channel = Channel::create([
            'user_id' => $user->id,
            'business_id' => $business->id,
            'type' => 'shopify',
            'page_id' => 'store.myshopify.com',
            'page_name' => 'Test Store',
            'access_token' => 'shopify-token',
            'status' => 'connected',
            'metadata' => [],
        ]);

        Http::fake(function (ClientRequest $request) {
            $query = (string) ($request->data()['query'] ?? '');
            if (str_contains($query, 'query Products')) {
                return Http::response(['data' => ['products' => ['edges' => [], 'pageInfo' => ['hasNextPage' => false]]]]);
            }
            if (str_contains($query, 'query Orders')) {
                $this->assertStringNotContainsString('customer {', $query);
                $this->assertStringNotContainsString('shippingAddress {', $query);
                return Http::response(['data' => ['orders' => ['edges' => [], 'pageInfo' => ['hasNextPage' => false]]]]);
            }

            return Http::response([
                'data' => ['customers' => null],
                'errors' => [['message' => 'This app is not approved to access the Customer object.']],
            ]);
        });

        $counts = app(CommerceStoreSyncService::class)->sync($channel);

        $this->assertSame(['products' => 0, 'orders' => 0, 'customers' => 0], $counts);
        $this->assertSame('connected', $channel->fresh()->metadata['sync_status']);
        $this->assertSame(
            ['Shopify customer sync is paused until Protected Customer Data access is approved.'],
            $channel->fresh()->metadata['sync_warnings']
        );
    }
}
