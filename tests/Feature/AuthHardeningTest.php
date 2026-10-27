<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AuthHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_is_limited_by_email_and_ip_then_succeeds_after_the_window(): void
    {
        $user = User::factory()->create([
            'email' => 'throttle@example.test',
            'email_verified_at' => now(),
        ]);
        $payload = ['email' => $user->email, 'password' => 'incorrect-password'];

        foreach (range(1, 5) as $_) {
            $this->postJson('/api/auth/login', $payload)->assertUnprocessable();
        }

        $this->postJson('/api/auth/login', $payload)
            ->assertStatus(429)
            ->assertHeader('Retry-After');

        $this->travel(61)->seconds();
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password'])
            ->assertOk()
            ->assertJsonStructure(['token', 'user']);
    }

    public function test_unverified_user_cannot_receive_a_login_token(): void
    {
        $user = User::factory()->create(['email_verified_at' => null]);

        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password'])
            ->assertForbidden()
            ->assertJsonPath('error_code', 'EMAIL_NOT_VERIFIED');

        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_email_verification_is_persisted_and_allows_subsequent_login(): void
    {
        $user = User::factory()->create(['email_verified_at' => null]);
        DB::table('email_verification_otps')->insert([
            'email' => $user->email,
            'otp' => Hash::make('123456'),
            'created_at' => now(),
        ]);

        $this->postJson('/api/auth/verify-email', [
            'email' => $user->email,
            'otp' => '123456',
        ])->assertOk();

        $this->assertNotNull($user->fresh()->email_verified_at);

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk()->assertJsonStructure(['token', 'user']);
    }

    public function test_verified_user_can_log_in_and_receive_a_token(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password'])
            ->assertOk()
            ->assertJsonStructure(['token', 'user']);

        $this->assertSame(1, $user->tokens()->count());
    }

    public function test_login_token_expires_after_three_days_by_default_or_seven_days_when_remembered(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        $beforeDefaultLogin = now();
        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
            'remember_me' => false,
        ])->assertOk();
        $defaultExpiry = \Illuminate\Support\Carbon::parse($user->tokens()->latest('id')->value('expires_at'));
        $this->assertTrue($defaultExpiry->between($beforeDefaultLogin->copy()->addDays(3)->subMinute(), now()->addDays(3)->addMinute()), 'Stored default expiry: '.$defaultExpiry->toIso8601String().'; lower='.$beforeDefaultLogin->copy()->addDays(3)->subMinute()->toIso8601String().'; upper='.now()->addDays(3)->addMinute()->toIso8601String());

        $beforeRememberedLogin = now();
        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
            'remember_me' => true,
        ])->assertOk();
        $rememberedExpiry = \Illuminate\Support\Carbon::parse($user->tokens()->latest('id')->value('expires_at'));
        $this->assertTrue($rememberedExpiry->between($beforeRememberedLogin->copy()->addDays(7)->subMinute(), now()->addDays(7)->addMinute()));
    }

    public function test_legacy_email_backfill_marks_preexisting_users_and_rolls_back_safely(): void
    {
        $legacyUser = User::factory()->create(['email_verified_at' => null]);
        Schema::dropIfExists('legacy_email_verification_backfills');
        $migration = require database_path('migrations/2026_10_10_000034_backfill_legacy_verified_emails.php');

        $migration->up();
        $this->assertNotNull($legacyUser->fresh()->email_verified_at);
        $this->assertTrue($legacyUser->fresh()->email_verified_at->isBefore(now()));

        $migration->down();
        $this->assertNull($legacyUser->fresh()->email_verified_at);
        $this->assertFalse(Schema::hasTable('legacy_email_verification_backfills'));
    }

    public function test_password_change_revokes_other_tokens_and_preserves_the_current_token(): void
    {
        $user = User::factory()->create(['password' => Hash::make('password')]);
        $oldToken = $user->createToken('old-session');
        $currentToken = $user->createToken('current-session');

        $this->withToken($currentToken->plainTextToken)->postJson('/api/auth/password', [
            'current_password' => 'password',
            'new_password' => 'new-password',
            'new_password_confirmation' => 'new-password',
        ])->assertOk();

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $oldToken->accessToken->id]);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $currentToken->accessToken->id]);
        Auth::forgetGuards();
        $this->withToken($oldToken->plainTextToken)->getJson('/api/auth/user')->assertUnauthorized();
        Auth::forgetGuards();
        $this->withToken($currentToken->plainTextToken)->getJson('/api/auth/user')->assertOk();
    }

    public function test_password_reset_revokes_existing_tokens(): void
    {
        $user = User::factory()->create(['email' => 'reset@example.test']);
        $user->createToken('session-one');
        $user->createToken('session-two');
        DB::table('password_reset_tokens')->insert([
            'email' => $user->email,
            'token' => Hash::make('123456'),
            'created_at' => now(),
        ]);

        $this->postJson('/api/auth/reset-password', [
            'email' => $user->email,
            'otp' => '123456',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertOk();

        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_expired_sanctum_token_is_rejected(): void
    {
        config(['sanctum.expiration' => 60]);
        $user = User::factory()->create();
        $token = $user->createToken('expired-session');
        DB::table('personal_access_tokens')->where('id', $token->accessToken->id)
            ->update(['created_at' => now()->subMinutes(61)]);

        $this->withToken($token->plainTextToken)->getJson('/api/auth/user')->assertUnauthorized();
    }
}
