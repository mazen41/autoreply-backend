<?php

namespace Tests\Feature;

use App\Models\Bot;
use App\Models\BusinessKnowledgeFile;
use App\Models\BusinessProfile;
use App\Models\Channel;
use App\Models\Package;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BotTenantAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Package::create([
            'name' => 'Free',
            'name_ar' => 'Free',
            'price_monthly' => 0,
            'price_yearly' => 0,
            'ai_replies_limit' => -1,
            'is_active' => true,
        ]);
    }

    public function test_bot_creation_cannot_clear_another_business_primary_channel_assignment(): void
    {
        [$owner, $business] = $this->makeBusiness('Owner A');
        [$otherOwner, $otherBusiness] = $this->makeBusiness('Owner B');
        $ownedChannel = $this->makeChannel($owner, $business, 'Owned channel');
        $foreignChannel = $this->makeChannel($otherOwner, $otherBusiness, 'Foreign channel');
        $foreignBot = Bot::create([
            'business_profile_id' => $otherBusiness->id,
            'name' => 'Foreign bot',
            'status' => 'active',
        ]);
        $foreignBot->channels()->attach($foreignChannel->id, ['is_primary' => true]);

        Sanctum::actingAs($owner);
        $this->withHeader('X-Business-Id', (string) $business->id)->postJson('/api/bots', [
            'name' => 'My bot',
            'channel_ids' => [$ownedChannel->id],
            'primary_channel_ids' => [$foreignChannel->id],
        ])->assertCreated();

        $this->assertDatabaseHas('bot_channels', [
            'bot_id' => $foreignBot->id,
            'channel_id' => $foreignChannel->id,
            'is_primary' => true,
        ]);
    }

    public function test_bot_creation_rejects_knowledge_file_from_another_business(): void
    {
        [$owner, $business] = $this->makeBusiness('Owner A');
        [, $foreignBusiness] = $this->makeBusiness('Owner B');
        $foreignFile = BusinessKnowledgeFile::create([
            'business_profile_id' => $foreignBusiness->id,
            'filename' => 'private.txt',
            'file_type' => 'txt',
            'extracted_text' => 'Private knowledge',
        ]);

        Sanctum::actingAs($owner);
        $this->withHeader('X-Business-Id', (string) $business->id)->postJson('/api/bots', [
            'name' => 'My bot',
            'knowledge_assignments' => [['file_id' => $foreignFile->id]],
        ])->assertUnprocessable();

        $this->assertDatabaseMissing('bots', [
            'business_profile_id' => $business->id,
            'name' => 'My bot',
        ]);
    }

    public function test_bot_creation_accepts_knowledge_file_owned_by_its_business(): void
    {
        [$owner, $business] = $this->makeBusiness('Owner A');
        $file = BusinessKnowledgeFile::create([
            'business_profile_id' => $business->id,
            'filename' => 'public.txt',
            'file_type' => 'txt',
            'extracted_text' => 'Business knowledge',
        ]);

        Sanctum::actingAs($owner);
        $response = $this->withHeader('X-Business-Id', (string) $business->id)->postJson('/api/bots', [
            'name' => 'My bot',
            'knowledge_assignments' => [['file_id' => $file->id]],
        ])->assertCreated();

        $this->assertDatabaseHas('bot_knowledge_assignments', [
            'bot_id' => $response->json('bot.id'),
            'business_knowledge_file_id' => $file->id,
        ]);
    }

    public function test_agent_escalation_settings_are_saved_and_can_be_updated(): void
    {
        [$owner, $business] = $this->makeBusiness('Escalation owner');
        Sanctum::actingAs($owner);

        $response = $this->withHeader('X-Business-Id', (string) $business->id)->postJson('/api/bots', [
            'name' => 'Support agent',
            'ai_confidence_threshold' => 0.72,
            'escalation_config' => [
                'keywords' => ['refund request', 'manager please'],
                'intents' => ['complaint', 'customer_requested_human'],
            ],
        ])->assertCreated();

        $bot = Bot::findOrFail($response->json('bot.id'));
        $this->assertSame(0.72, $bot->ai_confidence_threshold);
        $this->assertSame(['refund request', 'manager please'], $bot->escalation_config['keywords']);
        $this->assertSame(['complaint', 'customer_requested_human'], $bot->escalation_config['intents']);

        $this->patchJson('/api/bots/' . $bot->id, [
            'escalation_config' => ['keywords' => ['urgent refund'], 'intents' => []],
        ], ['X-Business-Id' => (string) $business->id])->assertOk();

        $this->assertSame(['urgent refund'], $bot->fresh()->escalation_config['keywords']);
    }

    public function test_new_agent_inherits_business_ai_provider_and_model(): void
    {
        [$owner, $business] = $this->makeBusiness('AI inheritance owner');
        $business->forceFill(['ai_provider' => 'groq', 'ai_model' => 'openai/gpt-oss-120b'])->save();
        Sanctum::actingAs($owner);

        $response = $this->withHeader('X-Business-Id', (string) $business->id)->postJson('/api/bots', [
            'name' => 'Inheriting agent',
        ])->assertCreated();

        $this->assertNull($response->json('bot.ai_provider'));
        $this->assertNull($response->json('bot.ai_model'));
        $this->assertSame('groq', $business->fresh()->ai_provider);
    }

    public function test_agent_rejects_ai_providers_not_supported_by_runtime(): void
    {
        [$owner, $business] = $this->makeBusiness('Provider validation owner');
        Sanctum::actingAs($owner);

        $this->withHeader('X-Business-Id', (string) $business->id)
            ->postJson('/api/bots', ['name' => 'Unsupported provider', 'ai_provider' => 'anthropic'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['ai_provider']);

        $this->assertDatabaseMissing('bots', [
            'business_profile_id' => $business->id,
            'name' => 'Unsupported provider',
        ]);
    }

    public function test_bot_wizard_saves_channel_store_routing_with_the_agent_request(): void
    {
        [$owner, $business] = $this->makeBusiness('Wizard owner');
        $communicationChannel = $this->makeChannel($owner, $business, 'Instagram');
        $store = Channel::create([
            'user_id' => $owner->id,
            'business_id' => $business->id,
            'type' => 'salla',
            'page_id' => 'wizard-store',
            'page_name' => 'Primary Store',
            'access_token' => 'test-store-token',
            'status' => 'connected',
        ]);
        Sanctum::actingAs($owner);

        $response = $this->withHeader('X-Business-Id', (string) $business->id)->postJson('/api/bots', [
            'name' => 'Wizard agent',
            'channel_ids' => [$communicationChannel->id],
            'primary_channel_ids' => [$communicationChannel->id],
            'ecommerce_connection_ids' => [$store->id],
            'default_ecommerce_connection_id' => $store->id,
            'channel_store_routing' => [$communicationChannel->id => $store->id],
        ])->assertCreated();

        $this->assertDatabaseHas('bot_channels', [
            'bot_id' => $response->json('bot.id'),
            'channel_id' => $communicationChannel->id,
            'is_primary' => true,
            'ecommerce_connection_id' => $store->id,
        ]);
        $this->assertNull($communicationChannel->fresh()->default_ecommerce_connection_id);
    }

    public function test_invalid_wizard_store_route_does_not_create_a_partial_agent(): void
    {
        [$owner, $business] = $this->makeBusiness('Wizard validation owner');
        $communicationChannel = $this->makeChannel($owner, $business, 'Instagram');
        Sanctum::actingAs($owner);

        $this->withHeader('X-Business-Id', (string) $business->id)->postJson('/api/bots', [
            'name' => 'Invalid wizard agent',
            'channel_ids' => [$communicationChannel->id],
            'channel_store_routing' => [$communicationChannel->id => 999999],
        ])->assertUnprocessable();

        $this->assertDatabaseMissing('bots', [
            'business_profile_id' => $business->id,
            'name' => 'Invalid wizard agent',
        ]);
        $this->assertNull($communicationChannel->fresh()->default_ecommerce_connection_id);
    }

    public function test_database_failure_saving_channel_route_rolls_back_the_entire_agent_setup(): void
    {
        [$owner, $business] = $this->makeBusiness('Wizard transaction owner');
        $communicationChannel = $this->makeChannel($owner, $business, 'Instagram');
        $store = Channel::create([
            'user_id' => $owner->id,
            'business_id' => $business->id,
            'type' => 'shopify',
            'page_id' => 'transaction-store',
            'page_name' => 'Transaction Store',
            'access_token' => 'test-store-token',
            'status' => 'connected',
        ]);
        DB::statement("CREATE TRIGGER fail_wizard_route BEFORE INSERT ON bot_channels WHEN NEW.channel_id = {$communicationChannel->id} BEGIN SELECT RAISE(ABORT, 'simulated route failure'); END");
        Sanctum::actingAs($owner);

        $this->withHeader('X-Business-Id', (string) $business->id)->postJson('/api/bots', [
            'name' => 'Must roll back agent',
            'channel_ids' => [$communicationChannel->id],
            'primary_channel_ids' => [$communicationChannel->id],
            'ecommerce_connection_ids' => [$store->id],
            'default_ecommerce_connection_id' => $store->id,
            'channel_store_routing' => [$communicationChannel->id => $store->id],
        ])->assertServerError();

        $this->assertDatabaseMissing('bots', ['business_profile_id' => $business->id, 'name' => 'Must roll back agent']);
        $this->assertDatabaseMissing('bot_channels', ['channel_id' => $communicationChannel->id]);
        $this->assertNull($communicationChannel->fresh()->default_ecommerce_connection_id);
        $this->assertDatabaseMissing('bot_ecommerce_connections', ['ecommerce_connection_id' => $store->id]);
    }

    public function test_inaccessible_business_header_is_rejected_instead_of_falling_back(): void
    {
        [$owner, $business] = $this->makeBusiness('Owner A');
        [, $foreignBusiness] = $this->makeBusiness('Owner B');
        Sanctum::actingAs($owner);

        $this->withHeader('X-Business-Id', (string) $foreignBusiness->id)
            ->postJson('/api/bots', ['name' => 'Must not be created'])
            ->assertForbidden();

        $this->assertDatabaseMissing('bots', [
            'business_profile_id' => $business->id,
            'name' => 'Must not be created',
        ]);
    }

    public function test_bot_routes_require_an_explicit_business_even_when_user_has_a_primary(): void
    {
        [$owner, $business] = $this->makeBusiness('Owner');
        $owner->update(['business_id' => $business->id]);
        Sanctum::actingAs($owner);

        $this->getJson('/api/bots')->assertUnprocessable();
        $this->postJson('/api/bots', ['name' => 'No implicit business'])->assertUnprocessable();
        $this->assertDatabaseMissing('bots', [
            'business_profile_id' => $business->id,
            'name' => 'No implicit business',
        ]);
    }

    private function makeBusiness(string $name): array
    {
        $user = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $user->id, 'name' => $name]);
        $user->forceFill(['business_id' => $business->id])->save();

        return [$user, $business];
    }

    private function makeChannel(User $user, BusinessProfile $business, string $name): Channel
    {
        return Channel::create([
            'user_id' => $user->id,
            'business_id' => $business->id,
            'type' => 'instagram',
            'page_id' => strtolower(str_replace(' ', '-', $name)),
            'page_name' => $name,
            'access_token' => 'test-token',
            'status' => 'connected',
            'ai_enabled' => true,
        ]);
    }
}
