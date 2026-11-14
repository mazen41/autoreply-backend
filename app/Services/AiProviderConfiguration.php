<?php

namespace App\Services;

use App\Models\AiProvider;
use Illuminate\Support\Facades\DB;

class AiProviderConfiguration
{
    private const KEY_PATHS = [
        'openai' => 'services.openai.api_key',
        'claude' => 'services.claude.api_key',
        'gemini' => 'services.gemini.api_key',
        'groq' => 'services.groq.api_key',
    ];

    public function key(string $provider): string
    {
        if (!array_key_exists($provider, self::KEY_PATHS)) return '';
        $configured = $this->record($provider);
        if ($configured && !$configured->is_active) {
            // If custom credentials were intentionally stored in database, honor is_active flag.
            if ($configured->credentials !== null && !empty($configured->credentials)) {
                return '';
            }
            // If configured via environment key, self-heal and activate provider
            $envKey = (string) config(self::KEY_PATHS[$provider], '');
            if ($envKey !== '') {
                $configured->update(['is_active' => true]);
                return $envKey;
            }
            return '';
        }
        $key = data_get($configured?->credentials, 'api_key');
        return is_string($key) && $key !== '' ? $key : (string) config(self::KEY_PATHS[$provider], '');
    }

    public function isAvailable(string $provider): bool
    {
        return $this->key($provider) !== '';
    }

    public function isExplicitlyDisabled(string $provider): bool
    {
        return (bool) ($this->record($provider)?->is_active === false);
    }

    public function timeoutSeconds(string $provider): int
    {
        return max(1, min(180, (int) ($this->record($provider)?->timeout_seconds ?? config('services.ai.timeout', 30))));
    }

    private function record(string $provider): ?AiProvider
    {
        if (!DB::getSchemaBuilder()->hasTable('ai_providers')) return null;
        return AiProvider::query()->where('slug', $provider)->first();
    }
}
