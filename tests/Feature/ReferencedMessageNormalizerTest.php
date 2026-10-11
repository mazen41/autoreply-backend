<?php

namespace Tests\Feature;

use App\Services\ReferencedMessageNormalizer;
use Tests\TestCase;

class ReferencedMessageNormalizerTest extends TestCase
{
    public function test_meta_messenger_and_instagram_fixtures_normalize_reply_mid(): void
    {
        $normalizer = app(ReferencedMessageNormalizer::class);
        foreach (['messenger-reply.json', 'instagram-reply.json'] as $fixture) {
            $payload = json_decode(file_get_contents(base_path('docs/webhooks/' . $fixture)), true, flags: JSON_THROW_ON_ERROR);
            $message = $payload['entry'][0]['messaging'][0]['message'];
            $this->assertSame('OUTBOUND_PRODUCT_MID', $normalizer->meta($message), $fixture);
        }
    }

    public function test_whatsapp_cloud_context_id_is_normalized(): void
    {
        $payload = json_decode(file_get_contents(base_path('docs/webhooks/whatsapp-cloud-reply.json')), true, flags: JSON_THROW_ON_ERROR);
        $message = $payload['entry'][0]['changes'][0]['value']['messages'][0];
        $this->assertSame('OUTBOUND_PRODUCT_WAMID', app(ReferencedMessageNormalizer::class)->whatsappCloud($message));
    }

    public function test_evolution_sibling_context_info_is_normalized(): void
    {
        $payload = json_decode(file_get_contents(base_path('docs/webhooks/evolution-reply.json')), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(
            'OUTBOUND_PRODUCT_ID',
            app(ReferencedMessageNormalizer::class)->evolution($payload['data'], $payload['data']['message'])
        );
    }

    public function test_telegram_reference_includes_chat_id_to_avoid_id_collisions(): void
    {
        $payload = json_decode(file_get_contents(base_path('docs/webhooks/telegram-reply.json')), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('12345:101', app(ReferencedMessageNormalizer::class)->telegram($payload['message'], $payload['message']['chat']['id']));
    }

    public function test_gmail_in_reply_to_is_stripped_and_thread_fixture_is_documented(): void
    {
        $payload = json_decode(file_get_contents(base_path('docs/webhooks/gmail-reply.json')), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('RFC_MESSAGE_ID', app(ReferencedMessageNormalizer::class)->gmail($payload['headers']['In-Reply-To']));
    }
}
