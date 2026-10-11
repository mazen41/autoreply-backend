<?php

namespace Tests\Feature;

use App\Models\BusinessProfile;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\ProductMessageMap;
use App\Models\User;
use App\Services\ReferencedProductResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReferencedProductResolverTest extends TestCase
{
    use RefreshDatabase;

    private function setupCommerce(): array
    {
        $user = User::forceCreate([
            'name' => 'Reference Test',
            'email' => 'reference-' . uniqid() . '@example.test',
            'password' => bcrypt('password'),
        ]);
        $business = BusinessProfile::create(['user_id' => $user->id, 'business_name' => 'Reference Store']);
        $channel = Channel::create([
            'user_id' => $user->id,
            'business_id' => $business->id,
            'type' => 'facebook',
            'access_token' => 'test-token',
            'page_id' => 'reference-page',
            'status' => 'connected',
            'ai_enabled' => true,
        ]);
        $store = Channel::create([
            'user_id' => $user->id,
            'business_id' => $business->id,
            'type' => 'salla',
            'status' => 'connected',
            'access_token' => 'test-token',
        ]);
        $conversation = Conversation::create([
            'channel_id' => $channel->id,
            'business_id' => $business->id,
            'ecommerce_connection_id' => $store->id,
            'sender_id' => 'test-customer',
            'status' => 'open',
        ]);

        return compact('user', 'business', 'channel', 'store', 'conversation');
    }

    private function addMap(Conversation $conversation, Channel $store, string $platformId, string $externalId, ?string $name = null): ProductMessageMap
    {
        return ProductMessageMap::create([
            'conversation_id' => $conversation->id,
            'channel_id' => $conversation->channel_id,
            'platform_message_id' => $platformId,
            'salla_product_id' => $externalId,
            'commerce_channel_id' => $store->id,
            'commerce_external_id' => $externalId,
            'provider' => 'facebook',
            'commerce_provider' => $store->type,
            'product_name' => $name ?? 'Product ' . $externalId,
            'product_price' => '100',
            'currency' => 'SAR',
            'image_url' => 'https://images.example.test/' . $externalId . '.jpg',
            'sent_at' => now()->subMinute(),
        ]);
    }

    private function inbound(Conversation $conversation, string $text, array $metadata = []): Message
    {
        return Message::create([
            'conversation_id' => $conversation->id,
            'content' => $text,
            'direction' => 'inbound',
            'status' => 'received',
            'metadata' => $metadata,
        ]);
    }

    public function test_resolves_exact_quote_with_store_scoping(): void
    {
        $f = $this->setupCommerce();
        $this->addMap($f['conversation'], $f['store'], 'provider-image-1', 'sku-1', 'Blue Shirt');
        $message = $this->inbound($f['conversation'], 'I want to order this', [
            'referenced_platform_message_id' => 'provider-image-1',
        ]);

        $result = app(ReferencedProductResolver::class)->resolve($message, $f['conversation'], $f['store']);

        $this->assertSame('resolved', $result['resolution']);
        $this->assertSame('quote', $result['match_method']);
        $this->assertSame('sku-1', $result['product']['commerce_external_id']);
        $this->assertSame('place_order', app(ReferencedProductResolver::class)->intentFor($message->content, $result));
    }

    public function test_rejects_quote_from_another_conversation_or_store(): void
    {
        $f = $this->setupCommerce();
        $other = Conversation::create([
            'channel_id' => $f['channel']->id,
            'business_id' => $f['business']->id,
            'ecommerce_connection_id' => $f['store']->id,
            'sender_id' => 'another-customer',
            'status' => 'open',
        ]);
        $this->addMap($other, $f['store'], 'foreign-image', 'foreign-sku');
        $message = $this->inbound($f['conversation'], 'this one', ['referenced_platform_message_id' => 'foreign-image']);

        $result = app(ReferencedProductResolver::class)->resolve($message, $f['conversation'], $f['store']);

        $this->assertSame('none', $result['resolution']);
        $this->assertNull($result['product']);
    }

    public function test_missing_external_product_identity_is_not_resolved(): void
    {
        $f = $this->setupCommerce();
        $map = $this->addMap($f['conversation'], $f['store'], 'legacy-image', 'legacy-sku');
        $map->update(['commerce_external_id' => null]);
        $message = $this->inbound($f['conversation'], 'I want to order this', ['referenced_platform_message_id' => 'legacy-image']);

        $result = app(ReferencedProductResolver::class)->resolve($message, $f['conversation'], $f['store']);

        $this->assertSame('ambiguous', $result['resolution']);
        $this->assertNull($result['product']);
    }

    public function test_pointer_uses_one_recent_product_but_not_as_an_order_by_itself(): void
    {
        $f = $this->setupCommerce();
        $this->addMap($f['conversation'], $f['store'], 'recent-image', 'recent-sku', 'Green Hat');
        $message = $this->inbound($f['conversation'], 'this');
        $resolver = app(ReferencedProductResolver::class);

        $result = $resolver->resolve($message, $f['conversation'], $f['store']);

        $this->assertSame('resolved_recent', $result['resolution']);
        $this->assertSame('question', $resolver->intentFor($message->content, $result));
        $this->assertSame('place_order', $resolver->intentFor('عايز اطلب ده', $result));
    }

    public function test_confirmation_of_one_recent_product_resumes_scoped_checkout(): void
    {
        $f = $this->setupCommerce();
        $this->addMap($f['conversation'], $f['store'], 'confirmation-image', 'confirmation-sku', 'Blue Shirt');
        Message::create([
            'conversation_id' => $f['conversation']->id,
            'content' => 'Do you mean Blue Shirt? Please confirm before I start the order.',
            'direction' => 'outbound',
            'status' => 'sent',
            'is_ai' => true,
            'metadata' => ['pending_product_confirmation' => [
                'commerce_channel_id' => $f['store']->id,
                'commerce_external_id' => 'confirmation-sku',
            ]],
        ]);
        $message = $this->inbound($f['conversation'], 'yes');
        $resolver = app(ReferencedProductResolver::class);

        $result = $resolver->resolve($message, $f['conversation'], $f['store']);

        $this->assertSame('resolved', $result['resolution']);
        $this->assertTrue($result['is_confirmation_followup']);
        $this->assertSame('confirmation-sku', $result['product']['commerce_external_id']);
        $this->assertSame('place_order', $resolver->intentFor($message->content, $result));
    }

    public function test_multiple_recent_products_are_ambiguous_and_no_context_is_none(): void
    {
        $f = $this->setupCommerce();
        $this->addMap($f['conversation'], $f['store'], 'recent-1', 'sku-1', 'First Product');
        $this->addMap($f['conversation'], $f['store'], 'recent-2', 'sku-2', 'Second Product');
        $resolver = app(ReferencedProductResolver::class);

        $ambiguous = $resolver->resolve($this->inbound($f['conversation'], 'ده'), $f['conversation'], $f['store']);
        $none = $resolver->resolve($this->inbound($f['conversation'], 'ده'), $f['conversation'], null);

        $this->assertSame('ambiguous', $ambiguous['resolution']);
        $this->assertCount(2, $ambiguous['candidates']);
        $this->assertSame('none', $none['resolution']);
    }
}
