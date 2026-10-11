<?php

namespace Tests\Feature;

use App\Jobs\ExportBusinessCustomerData;
use App\Models\BusinessProfile;
use App\Models\Channel;
use App\Models\CommerceOrder;
use App\Models\ContactConsent;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\DataRightsRequest;
use App\Models\Message;
use App\Models\User;
use App\Services\IncomingMediaStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BusinessDataRightsTest extends TestCase
{
    use RefreshDatabase;

    public function test_retention_settings_are_business_scoped_and_validated(): void
    {
        $owner = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $owner->id]);
        Sanctum::actingAs($owner);

        $this->getJson("/api/businesses/{$business->id}/data-retention")
            ->assertOk()->assertJsonPath('message_retention_days', 365)->assertJsonPath('media_retention_days', 30);
        $this->putJson("/api/businesses/{$business->id}/data-retention", [
            'message_retention_days' => 180,
            'media_retention_days' => 14,
            'consent_retention_days' => null,
        ])->assertOk()->assertJsonPath('message_retention_days', 180)->assertJsonPath('media_retention_days', 14);
        $this->putJson("/api/businesses/{$business->id}/data-retention", ['message_retention_days' => 1])->assertUnprocessable();

        $outsider = User::factory()->create();
        Sanctum::actingAs($outsider);
        $this->getJson("/api/businesses/{$business->id}/data-retention")->assertForbidden();
    }

    public function test_export_request_is_scoped_and_queues_business_archive_job(): void
    {
        Queue::fake();
        $owner = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $owner->id]);
        Sanctum::actingAs($owner);

        $this->postJson("/api/businesses/{$business->id}/data-exports", [])->assertAccepted()
            ->assertJsonPath('type', 'export')->assertJsonPath('status', 'pending');
        $dataRequest = DataRightsRequest::query()->firstOrFail();
        $this->assertSame($business->id, $dataRequest->business_id);
        Queue::assertPushed(ExportBusinessCustomerData::class, fn ($job) => $job->requestId === $dataRequest->id);
    }

    public function test_erasure_deletes_conversation_data_and_media_retains_suppression_and_receipt(): void
    {
        Storage::fake('private_media');
        config(['services.evolution.incoming_media_disk' => 'private_media']);
        $owner = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $owner->id]);
        $channel = Channel::factory()->create(['user_id' => $owner->id, 'business_id' => $business->id, 'type' => 'instagram']);
        $customer = Customer::create([
            'business_profile_id' => $business->id, 'name' => 'Test Customer',
            'phone' => '+201155500000', 'email' => 'client@example.test',
        ]);
        $conversation = Conversation::factory()->create([
            'business_id' => $business->id, 'channel_id' => $channel->id,
            'customer_id' => $customer->id, 'sender_id' => 'instagram-user-erase', 'sender_email' => 'client@example.test',
        ]);
        $mediaPath = app(IncomingMediaStorage::class)->store('private image data', 'jpg');
        $message = Message::factory()->create([
            'conversation_id' => $conversation->id, 'media_url' => $mediaPath,
            'metadata' => ['media' => ['storage_disk' => 'private_media']],
        ]);
        ContactConsent::create([
            'business_id' => $business->id, 'channel' => 'email', 'contact_identifier' => 'client@example.test',
            'scope' => 'marketing', 'status' => 'opted_in', 'source' => 'test',
        ]);
        CommerceOrder::create([
            'business_id' => $business->id, 'channel_id' => $channel->id, 'external_id' => 'order-erase',
            'customer_name' => 'Test Customer', 'customer_email' => 'client@example.test',
            'customer_phone' => '+201155500000', 'shipping_address' => 'PII street',
        ]);

        Sanctum::actingAs($owner);
        $this->deleteJson("/api/businesses/{$business->id}/customers/{$customer->id}/data", ['confirm' => true])
            ->assertOk()->assertJsonPath('request.status', 'completed')
            ->assertJsonPath('request.receipt.customer_record_deleted', true)
            ->assertJsonPath('request.receipt.messages_deleted', 1)
            ->assertJsonPath('request.receipt.suppression_records_retained', 3);

        $this->assertDatabaseMissing('customers', ['id' => $customer->id]);
        $this->assertDatabaseMissing('conversations', ['id' => $conversation->id]);
        $this->assertDatabaseMissing('messages', ['id' => $message->id]);
        $this->assertDatabaseMissing('contact_consents', ['business_id' => $business->id, 'contact_identifier' => 'client@example.test']);
        $this->assertDatabaseHas('suppressions', ['business_id' => $business->id, 'channel' => 'email', 'contact_identifier' => 'client@example.test']);
        $this->assertDatabaseHas('suppressions', ['business_id' => $business->id, 'channel' => 'instagram', 'contact_identifier' => 'instagram-user-erase']);
        $this->assertDatabaseHas('commerce_orders', ['external_id' => 'order-erase', 'customer_email' => null, 'customer_phone' => null, 'shipping_address' => null]);
        Storage::disk('private_media')->assertMissing($mediaPath);
    }

    public function test_erasure_cannot_access_another_business_customer(): void
    {
        $owner = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $owner->id]);
        $otherOwner = User::factory()->create();
        $otherBusiness = BusinessProfile::factory()->create(['user_id' => $otherOwner->id]);
        $customer = Customer::create(['business_profile_id' => $otherBusiness->id, 'name' => 'Other']);
        Sanctum::actingAs($owner);

        $this->deleteJson("/api/businesses/{$business->id}/customers/{$customer->id}/data", ['confirm' => true])->assertNotFound();
        $this->assertDatabaseHas('customers', ['id' => $customer->id]);
    }

    public function test_export_job_creates_a_private_archive_with_customer_records(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $owner->id]);
        Customer::create(['business_profile_id' => $business->id, 'name' => 'Export Person', 'email' => 'export@example.test']);
        $dataRequest = DataRightsRequest::create(['business_id' => $business->id, 'requested_by' => $owner->id, 'type' => 'export']);

        (new ExportBusinessCustomerData($dataRequest->id))->handle();

        $completed = $dataRequest->fresh();
        $this->assertSame('completed', $completed->status);
        Storage::disk('local')->assertExists($completed->archive_path);
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open(Storage::disk('local')->path($completed->archive_path)) === true);
        $this->assertNotFalse($zip->locateName('customers.jsonl'));
        $this->assertStringContainsString('export@example.test', $zip->getFromName('customers.jsonl'));
        $zip->close();
    }

    public function test_retention_command_deletes_expired_messages_and_configured_consent_only(): void
    {
        $owner = User::factory()->create();
        $business = BusinessProfile::factory()->create([
            'user_id' => $owner->id, 'message_retention_days' => 30, 'consent_retention_days' => 60,
        ]);
        $channel = Channel::factory()->create(['user_id' => $owner->id, 'business_id' => $business->id]);
        $conversation = Conversation::factory()->create(['business_id' => $business->id, 'channel_id' => $channel->id]);
        $expired = Message::factory()->create(['conversation_id' => $conversation->id, 'created_at' => Carbon::now()->subDays(40)]);
        $recent = Message::factory()->create(['conversation_id' => $conversation->id, 'created_at' => Carbon::now()->subDays(5)]);
        ContactConsent::create([
            'business_id' => $business->id, 'channel' => 'email', 'contact_identifier' => 'old@example.test',
            'scope' => 'marketing', 'status' => 'opted_in', 'source' => 'test', 'captured_at' => Carbon::now()->subDays(90),
        ]);
        ContactConsent::create([
            'business_id' => $business->id, 'channel' => 'email', 'contact_identifier' => 'new@example.test',
            'scope' => 'marketing', 'status' => 'opted_in', 'source' => 'test', 'captured_at' => Carbon::now()->subDays(20),
        ]);

        $this->artisan('data:apply-retention')->assertExitCode(0);

        $this->assertDatabaseMissing('messages', ['id' => $expired->id]);
        $this->assertDatabaseHas('messages', ['id' => $recent->id]);
        $this->assertDatabaseMissing('contact_consents', ['contact_identifier' => 'old@example.test']);
        $this->assertDatabaseHas('contact_consents', ['contact_identifier' => 'new@example.test']);
    }
}
