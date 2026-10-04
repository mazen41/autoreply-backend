<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MetaWebhookSignatureTest extends TestCase
{
    private const APP_SECRET = 'test-meta-app-secret';
    private const WEBHOOK_URI = '/api/webhook/meta';

    protected function setUp(): void
    {
        parent::setUp();

        // Pin the secret — otherwise a locally-configured .env secret is used
        // and signatures computed against the test secret fail.
        config(['services.meta.app_secret' => self::APP_SECRET]);
    }

    private function signedPayload(string $body, ?string $secret = self::APP_SECRET): string
    {
        return 'sha256=' . hash_hmac('sha256', $body, $secret ?? '');
    }

    private function postWebhook(string $rawBody, array $headers)
    {
        return $this->call(
            'POST',
            self::WEBHOOK_URI,
            [],
            [],
            [],
            $this->transformHeadersToServerVars(array_merge([
                'Content-Type' => 'application/json',
            ], $headers)),
            $rawBody
        );
    }

    public function test_valid_signature_is_accepted()
    {
        $rawBody = json_encode([
            'object' => 'page',
            'entry' => [],
        ]);

        $response = $this->postWebhook($rawBody, [
            'X-Hub-Signature-256' => $this->signedPayload($rawBody),
        ]);

        $response->assertStatus(200);
        $response->assertSee('EVENT_RECEIVED');
    }

    public function test_invalid_signature_is_rejected_with_403()
    {
        $rawBody = json_encode([
            'object' => 'page',
            'entry' => [],
        ]);

        $response = $this->postWebhook($rawBody, [
            'X-Hub-Signature-256' => 'sha256=' . str_repeat('0', 64),
        ]);

        $response->assertStatus(403);
    }

    public function test_signature_over_mutated_body_is_rejected_with_403()
    {
        // Simulates a payload whose body was altered in transit: the
        // signature is valid for DIFFERENT content than what is received.
        $rawBody = json_encode(['object' => 'page', 'entry' => []]);
        $mutatedBody = json_encode(['object' => 'page', 'entry' => [], 'injected' => true]);

        $response = $this->postWebhook($rawBody, [
            'X-Hub-Signature-256' => $this->signedPayload($mutatedBody),
        ]);

        $response->assertStatus(403);
    }

    public function test_missing_signature_is_rejected_when_secret_is_configured()
    {
        $rawBody = json_encode([
            'object' => 'page',
            'entry' => [],
        ]);

        $response = $this->postWebhook($rawBody, []);

        $response->assertStatus(403);
    }

    public function test_missing_signature_is_allowed_when_no_secret_is_configured()
    {
        config(['services.meta.app_secret' => null]);

        $rawBody = json_encode([
            'object' => 'page',
            'entry' => [],
        ]);

        $response = $this->postWebhook($rawBody, []);

        // Cannot verify without a secret — misconfiguration must not
        // silently drop all intake, so the request is allowed with a warning.
        $response->assertStatus(200);
        $response->assertSee('EVENT_RECEIVED');
    }
}
