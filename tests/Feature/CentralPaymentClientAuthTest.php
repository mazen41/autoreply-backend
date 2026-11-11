<?php

namespace Tests\Feature;

use App\Models\CentralPayment;
use App\Models\PaymentClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CentralPaymentClientAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_can_only_read_its_own_payment_and_nonce_cannot_be_replayed(): void
    {
        $apiKey = bin2hex(random_bytes(32));
        $secret = bin2hex(random_bytes(32));
        $client = PaymentClient::query()->create([
            'public_id' => (string) Str::uuid(),
            'slug' => 'nazbiz-test',
            'name' => 'NazBiz test',
            'api_key_hash' => hash('sha256', $apiKey),
            'hmac_secret' => $secret,
            'notification_secret' => bin2hex(random_bytes(32)),
            'backend_url' => 'https://api.nazbiz.io',
            'frontend_url' => 'https://nazbiz.io',
            'is_active' => true,
        ]);
        $payment = CentralPayment::query()->create([
            'uuid' => (string) Str::uuid(),
            'payment_client_id' => $client->id,
            'external_order_id' => 'order-100',
            'amount' => 99,
            'currency' => 'SAR',
            'status' => 'pending',
            'idempotency_key' => 'idem-100',
        ]);

        $timestamp = (string) now()->timestamp;
        $nonce = Str::random(32);
        $uri = '/api/v1/payments/' . $payment->uuid;
        $canonical = implode("\n", ['GET', $uri, $timestamp, $nonce, hash('sha256', '')]);
        $signature = hash_hmac('sha256', $canonical, $secret);
        $headers = [
            'Authorization' => 'Bearer ' . $apiKey,
            'X-Payment-Client' => $client->public_id,
            'X-Payment-Timestamp' => $timestamp,
            'X-Payment-Nonce' => $nonce,
            'X-Payment-Signature' => $signature,
        ];

        $firstResponse = $this->get($uri, $headers);
        $firstResponse
            ->assertOk()
            ->assertJsonPath('payment_id', $payment->uuid)
            ->assertJsonPath('status', 'pending');

        $this->get($uri, $headers)
            ->assertStatus(409)
            ->assertJsonPath('message', 'Request nonce has already been used.');
    }

    public function test_unauthenticated_client_cannot_query_payment_status(): void
    {
        $this->getJson('/api/v1/payments/' . Str::uuid())
            ->assertUnauthorized();
    }
}
