<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\OAuthLoginExchange;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OAuthLoginExchangeTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_exchange_code_is_short_lived_single_use_and_returns_a_session_token(): void
    {
        $user = User::factory()->create();
        $code = app(OAuthLoginExchange::class)->issue($user, 'google', true);

        $this->assertNotSame($user->createToken('test')->plainTextToken, $code);
        $response = $this->postJson('/api/auth/exchange', ['code' => $code])
            ->assertOk()
            ->assertJsonPath('provider', 'google')
            ->assertJsonPath('is_new_user', true);

        $this->assertNotEmpty($response->json('token'));
        $this->postJson('/api/auth/exchange', ['code' => $code])->assertUnprocessable();
    }

    public function test_exchange_rejects_missing_or_expired_code(): void
    {
        $this->postJson('/api/auth/exchange', ['code' => 'expired'])->assertUnprocessable();
        $this->postJson('/api/auth/exchange', [])->assertUnprocessable();
    }
}
