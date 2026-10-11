<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class OAuthStateService
{
    private const TTL_MINUTES = 5;

    /** Create a high-entropy, provider-bound, single-use OAuth state value. */
    public function issue(string $provider, int $userId, array $context = []): string
    {
        $state = 'naz_' . Str::random(64);
        Cache::put($this->key($provider, $state), [
            'user_id' => $userId,
            'context' => $context,
            'issued_at' => now()->timestamp,
        ], now()->addMinutes(self::TTL_MINUTES));

        return $state;
    }

    /** Consume and validate state. A successful callback cannot reuse it. */
    public function consume(string $provider, ?string $state): ?array
    {
        if (!is_string($state) || !preg_match('/^naz_[A-Za-z0-9]{64}$/', $state)) {
            return null;
        }

        $data = Cache::pull($this->key($provider, $state));
        if (!is_array($data)
            || empty($data['user_id'])
            || !is_array($data['context'] ?? null)
            || now()->timestamp - (int) ($data['issued_at'] ?? 0) > self::TTL_MINUTES * 60) {
            return null;
        }

        return [
            'user_id' => (int) $data['user_id'],
            'context' => $data['context'],
        ];
    }

    private function key(string $provider, string $state): string
    {
        return 'oauth_state:' . strtolower($provider) . ':' . hash('sha256', $state);
    }
}
