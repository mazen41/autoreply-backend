<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class OAuthLoginExchange
{
    private const TTL_SECONDS = 60;

    public function issue(User $user, string $provider, bool $isNewUser): string
    {
        $code = Str::random(64);
        Cache::put($this->key($code), [
            'user_id' => $user->id,
            'provider' => $provider,
            'is_new_user' => $isNewUser,
        ], now()->addSeconds(self::TTL_SECONDS));

        return $code;
    }

    public function consume(string $code): ?array
    {
        if ($code === '' || strlen($code) > 128) {
            return null;
        }

        $key = $this->key($code);
        return Cache::lock("{$key}:consume", 5)->block(2, fn () => Cache::pull($key));
    }

    private function key(string $code): string
    {
        return 'oauth_login_exchange:' . hash('sha256', $code);
    }
}
