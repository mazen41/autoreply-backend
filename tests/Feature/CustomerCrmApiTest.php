<?php

namespace Tests\Feature;

use App\Models\BusinessProfile;
use App\Models\Channel;
use App\Models\CommerceOrder;
use App\Models\ContactConsent;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\CustomerIdentity;
use App\Models\CustomerNote;
use App\Models\SocialCommentEvent;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class CustomerCrmApiTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $user = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $user->id]);
        $otherBusiness = BusinessProfile::factory()->create(['user_id' => User::factory()->create()->id]);
        $this->actingAs($user);
        return compact('user', 'business', 'otherBusiness');
    }

    private function headers(int $businessId): array
    {
        return ['X-Business-Id' => (string) $businessId, 'Accept' => 'application/json'];
    }

    public function test_contacts_are_business_scoped_and_filterable_by_stage_and_channel(): void
    {
        $f = $this->fixture();
        $channel = Channel::create([
            'user_id' => $f['user']->id, 'business_id' => $f['business']->id, 'type' => 'instagram',
            'page_id' => 'crm-page', 'page_name' => 'CRM Page', 'access_token' => 'test', 'status' => 'connected',
        ]);
        $customer = Customer::create([
            'business_profile_id' => $f['business']->id, 'name' => 'Sara', 'email' => 'sara@example.test',
            'tags' => ['vip'], 'custom_fields' => ['lifecycle_stage' => 'qualified'],
        ]);
        Conversation::create([
            'channel_id' => $channel->id, 'business_id' => $f['business']->id, 'customer_id' => $customer->id,
            'sender_id' => 'ig-sara', 'status' => 'open',
        ]);
        Customer::create(['business_profile_id' => $f['otherBusiness']->id, 'name' => 'Private Contact']);

        $response = $this->getJson('/api/customers?business_id=' . $f['business']->id . '&lifecycle_stage=qualified&channel_id=' . $channel->id, $this->headers($f['business']->id));

        $response->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $customer->id);
        $this->assertStringNotContainsString('Private Contact', $response->getContent());
    }

    public function test_contact_profile_uses_synced_orders_and_consent_not_checkout_drafts(): void
    {
        $f = $this->fixture();
        $channel = Channel::create([
            'user_id' => $f['user']->id, 'business_id' => $f['business']->id, 'type' => 'shopify',
            'page_id' => 'crm-store.myshopify.com', 'page_name' => 'CRM Store', 'access_token' => 'test', 'status' => 'connected',
        ]);
        $customer = Customer::create([
            'business_profile_id' => $f['business']->id, 'name' => 'Mazen', 'email' => 'mazen@example.test',
        ]);
        $conversation = Conversation::create([
            'channel_id' => $channel->id, 'business_id' => $f['business']->id, 'customer_id' => $customer->id,
            'sender_id' => 'ig-mazen', 'status' => 'open', 'checkout_state' => ['order_id' => 'DRAFT-ONLY', 'status' => 'confirmed'],
        ]);
        CommerceOrder::create([
            'business_id' => $f['business']->id, 'channel_id' => $channel->id, 'external_id' => 'shop-order-100',
            'order_number' => '100', 'status' => 'open', 'total' => 25, 'currency' => 'USD',
            'customer_email' => 'mazen@example.test', 'ordered_at' => now(), 'raw_data' => ['private' => 'do not return'],
        ]);
        ContactConsent::create([
            'business_id' => $f['business']->id, 'channel' => 'email', 'contact_identifier' => 'mazen@example.test',
            'scope' => 'marketing', 'status' => 'opted_in', 'source' => 'form', 'captured_at' => now(),
        ]);

        $response = $this->getJson('/api/customers/' . $customer->id . '?business_id=' . $f['business']->id, $this->headers($f['business']->id));

        $response->assertOk()->assertJsonPath('orders.0.order_number', '100')
            ->assertJsonPath('consents.0.status', 'opted_in')
            ->assertJsonMissingPath('orders.0.raw_data');
        $this->assertStringNotContainsString('DRAFT-ONLY', $response->getContent());
        $this->assertSame($conversation->id, $response->json('customer.conversations.0.id'));
    }

    public function test_note_cannot_be_attached_to_another_contacts_conversation(): void
    {
        $f = $this->fixture();
        $channel = Channel::create([
            'user_id' => $f['user']->id, 'business_id' => $f['business']->id, 'type' => 'facebook',
            'page_id' => 'crm-note-page', 'access_token' => 'test', 'status' => 'connected',
        ]);
        $first = Customer::create(['business_profile_id' => $f['business']->id, 'name' => 'First']);
        $second = Customer::create(['business_profile_id' => $f['business']->id, 'name' => 'Second']);
        $conversation = Conversation::create([
            'channel_id' => $channel->id, 'business_id' => $f['business']->id, 'customer_id' => $second->id,
            'sender_id' => 'fb-second', 'status' => 'open',
        ]);

        $this->postJson('/api/customers/' . $first->id . '/notes?business_id=' . $f['business']->id, [
            'content' => 'Misattached note', 'conversation_id' => $conversation->id,
        ], $this->headers($f['business']->id))->assertUnprocessable();
        $this->assertSame(0, CustomerNote::count());
    }

    public function test_csv_import_previews_then_skips_existing_contacts_and_export_is_scoped(): void
    {
        $f = $this->fixture();
        Customer::create(['business_profile_id' => $f['business']->id, 'name' => 'Existing', 'email' => 'existing@example.test']);
        Customer::create(['business_profile_id' => $f['business']->id, 'name' => '=HYPERLINK("https://bad.example")', 'email' => 'formula@example.test']);
        Customer::create(['business_profile_id' => $f['otherBusiness']->id, 'name' => 'Other Workspace', 'email' => 'other@example.test']);
        $csv = "name,email,phone,lifecycle_stage,tags\nNew Person,new@example.test,+15550001111,new_lead,lead|event\nExisting,existing@example.test,,customer,vip\n";

        $preview = $this->post('/api/customers/import?business_id=' . $f['business']->id, [
            'file' => UploadedFile::fake()->createWithContent('contacts.csv', $csv),
        ], $this->headers($f['business']->id));
        $preview->assertOk()->assertJsonPath('valid_rows', 2)->assertJsonPath('invalid_rows', 0);
        $this->assertSame(2, Customer::where('business_profile_id', $f['business']->id)->count());

        $imported = $this->post('/api/customers/import?business_id=' . $f['business']->id, [
            'file' => UploadedFile::fake()->createWithContent('contacts.csv', $csv), 'confirm' => true,
        ], $this->headers($f['business']->id));
        $imported->assertOk()->assertJsonPath('created', 1)->assertJsonPath('skipped_existing', 1);

        $export = $this->get('/api/customers/export?business_id=' . $f['business']->id, $this->headers($f['business']->id));
        $export->assertOk();
        $csvOutput = $export->streamedContent();
        $this->assertStringContainsString('new@example.test', $csvOutput);
        $this->assertStringNotContainsString('other@example.test', $csvOutput);
        $this->assertStringContainsString("'=HYPERLINK", $csvOutput);
    }

    public function test_profile_update_rejects_duplicate_email_and_protected_identity_mutation(): void
    {
        $f = $this->fixture();
        $one = Customer::create(['business_profile_id' => $f['business']->id, 'name' => 'One', 'email' => 'one@example.test']);
        $two = Customer::create(['business_profile_id' => $f['business']->id, 'name' => 'Two', 'email' => 'two@example.test']);

        $this->patchJson('/api/customers/' . $one->id . '?business_id=' . $f['business']->id, [
            'email' => 'two@example.test',
        ], $this->headers($f['business']->id))->assertStatus(409);

        $this->patchJson('/api/customers/' . $one->id . '/tags?business_id=' . $f['business']->id, [
            'custom_fields' => ['platform_ids' => ['instagram' => 'stolen-id']],
        ], $this->headers($f['business']->id))->assertUnprocessable();
        $this->assertSame('one@example.test', $one->fresh()->email);
        $this->assertSame('two@example.test', $two->fresh()->email);
    }

    public function test_contact_profile_includes_comment_history_only_for_its_account_identity(): void
    {
        $f = $this->fixture();
        $firstChannel = Channel::create([
            'user_id' => $f['user']->id, 'business_id' => $f['business']->id, 'type' => 'facebook',
            'page_id' => 'crm-comments-one', 'access_token' => 'test', 'status' => 'connected',
        ]);
        $secondChannel = Channel::create([
            'user_id' => $f['user']->id, 'business_id' => $f['business']->id, 'type' => 'facebook',
            'page_id' => 'crm-comments-two', 'access_token' => 'test', 'status' => 'connected',
        ]);
        $customer = Customer::create(['business_profile_id' => $f['business']->id, 'name' => 'Commenter']);
        CustomerIdentity::create([
            'business_profile_id' => $f['business']->id, 'customer_id' => $customer->id,
            'channel_id' => $firstChannel->id, 'platform' => 'facebook', 'external_id' => 'same-commenter-id',
            'external_id_hash' => hash('sha256', 'same-commenter-id'),
        ]);

        foreach ([[$firstChannel, 'Own account comment'], [$secondChannel, 'Other account comment']] as [$channel, $text]) {
            SocialCommentEvent::create([
                'channel_id' => $channel->id, 'business_id' => $f['business']->id, 'platform' => 'facebook',
                'event_type' => 'comment_created', 'account_id' => $channel->page_id,
                'comment_id' => 'comment-' . $channel->id, 'commenter_id' => 'same-commenter-id',
                'comment_text' => $text, 'status' => 'processed', 'payload' => ['private' => 'not returned'],
                'platform_created_at' => now(),
            ]);
        }

        $response = $this->getJson('/api/customers/' . $customer->id . '?business_id=' . $f['business']->id, $this->headers($f['business']->id));

        $response->assertOk()->assertJsonCount(1, 'comments')->assertJsonPath('comments.0.comment_text', 'Own account comment');
        $this->assertStringNotContainsString('Other account comment', $response->getContent());
        $this->assertStringNotContainsString('not returned', $response->getContent());
    }

    public function test_contact_assignment_accepts_only_active_business_members_with_assignment_roles(): void
    {
        $f = $this->fixture();
        $customer = Customer::create(['business_profile_id' => $f['business']->id, 'name' => 'Assigned contact']);
        $agent = User::factory()->create();
        TeamMember::create(['business_id' => $f['business']->id, 'user_id' => $agent->id, 'role' => 'agent', 'is_active' => true]);

        $this->getJson('/api/customers/assignees?business_id=' . $f['business']->id, $this->headers($f['business']->id))
            ->assertOk()->assertJsonFragment(['id' => $f['user']->id, 'role' => 'owner'])
            ->assertJsonFragment(['id' => $agent->id, 'role' => 'agent']);
        $this->patchJson('/api/customers/' . $customer->id . '?business_id=' . $f['business']->id, [
            'assigned_to' => $agent->id,
        ], $this->headers($f['business']->id))->assertOk()->assertJsonPath('customer.assigned_to', $agent->id);

        $otherUser = User::factory()->create();
        $this->patchJson('/api/customers/' . $customer->id . '?business_id=' . $f['business']->id, [
            'assigned_to' => $otherUser->id,
        ], $this->headers($f['business']->id))->assertNotFound();

        $viewer = User::factory()->create();
        TeamMember::create(['business_id' => $f['business']->id, 'user_id' => $viewer->id, 'role' => 'viewer', 'is_active' => true]);
        $this->patchJson('/api/customers/' . $customer->id . '?business_id=' . $f['business']->id, [
            'assigned_to' => $viewer->id,
        ], $this->headers($f['business']->id))->assertNotFound();
    }

    public function test_contact_merge_moves_linked_records_and_can_be_undone_without_overwriting_later_edits(): void
    {
        $f = $this->fixture();
        $channel = Channel::create([
            'user_id' => $f['user']->id, 'business_id' => $f['business']->id, 'type' => 'instagram',
            'page_id' => 'crm-merge-page', 'access_token' => 'test', 'status' => 'connected',
        ]);
        $source = Customer::create([
            'business_profile_id' => $f['business']->id, 'name' => 'Source person', 'email' => 'source@example.test',
            'tags' => ['source-tag'], 'lead_score' => 42, 'custom_fields' => ['language' => 'en'],
        ]);
        $target = Customer::create([
            'business_profile_id' => $f['business']->id, 'name' => 'Primary person', 'tags' => ['primary-tag'],
            'lead_score' => 10, 'custom_fields' => ['source' => 'inbox'],
        ]);
        $conversation = Conversation::create([
            'channel_id' => $channel->id, 'business_id' => $f['business']->id, 'customer_id' => $source->id,
            'sender_id' => 'merge-sender', 'status' => 'open',
        ]);
        $note = CustomerNote::create(['customer_id' => $source->id, 'user_id' => $f['user']->id, 'content' => 'Preserve this note']);
        $identity = CustomerIdentity::create([
            'business_profile_id' => $f['business']->id, 'customer_id' => $source->id,
            'channel_id' => $channel->id, 'platform' => 'instagram', 'external_id' => 'merge-sender',
            'external_id_hash' => hash('sha256', 'merge-sender'),
        ]);

        $mergeResponse = $this->postJson('/api/customers/' . $source->id . '/merge?business_id=' . $f['business']->id, [
            'target_customer_id' => $target->id,
        ], $this->headers($f['business']->id))->assertCreated()->assertJsonPath('merge.source_customer_id', $source->id);
        $mergeId = $mergeResponse->json('merge.id');
        $this->assertSame($target->id, $conversation->fresh()->customer_id);
        $this->assertSame($target->id, $note->fresh()->customer_id);
        $this->assertSame($target->id, $identity->fresh()->customer_id);
        $this->assertSame($target->id, $source->fresh()->merged_into_customer_id);
        $this->assertSame('source@example.test', $target->fresh()->email);
        $this->assertSame(['primary-tag', 'source-tag'], $target->fresh()->tags);
        $this->getJson('/api/customers?business_id=' . $f['business']->id, $this->headers($f['business']->id))
            ->assertOk()->assertJsonMissing(['id' => $source->id]);

        $target->update(['name' => 'Edited after merge']);
        $this->postJson('/api/customer-merges/' . $mergeId . '/unmerge?business_id=' . $f['business']->id, [], $this->headers($f['business']->id))
            ->assertOk();
        $this->assertNull($source->fresh()->merged_into_customer_id);
        $this->assertSame($source->id, $conversation->fresh()->customer_id);
        $this->assertSame($source->id, $note->fresh()->customer_id);
        $this->assertSame($source->id, $identity->fresh()->customer_id);
        $this->assertSame('Edited after merge', $target->fresh()->name);
        $this->assertNull($target->fresh()->email);
        $this->assertSame('source@example.test', $source->fresh()->email);
    }

    public function test_contact_merge_rejects_another_business_target_without_changing_source(): void
    {
        $f = $this->fixture();
        $source = Customer::create(['business_profile_id' => $f['business']->id, 'name' => 'Source']);
        $other = Customer::create(['business_profile_id' => $f['otherBusiness']->id, 'name' => 'Other business']);

        $this->postJson('/api/customers/' . $source->id . '/merge?business_id=' . $f['business']->id, [
            'target_customer_id' => $other->id,
        ], $this->headers($f['business']->id))->assertNotFound();
        $this->assertNull($source->fresh()->merged_into_customer_id);
        $this->assertSame(0, \App\Models\CustomerMergeRecord::count());
    }
}
