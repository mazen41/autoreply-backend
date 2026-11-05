<?php

namespace App\Services;

use App\Models\AiModelPrice;
use App\Models\BusinessProfile;
use Illuminate\Support\Facades\DB;

class AiRouter
{
    public function resolve(BusinessProfile $business, string $task, ?string $requestedProvider, ?string $requestedModel, array $requiredCapabilities = ['text']): array
    {
        $providers = app(AiProviderConfiguration::class);
        $businessSettings = DB::table('ai_business_settings')->where('business_id', $business->id)->first();
        $mode = in_array($businessSettings?->operating_mode, ['auto', 'economy', 'premium'], true) ? $businessSettings->operating_mode : 'auto';
        $enabledModes = $this->enabledModes();
        if (!in_array($mode, $enabledModes, true)) $mode = $enabledModes[0] ?? 'auto';
        $rule = DB::table('ai_routing_rules as rules')
            ->join('ai_model_prices as models', 'models.id', '=', 'rules.primary_model_id')
            ->where('rules.task_type', $task)->where('rules.operating_mode', $mode)
            ->where('rules.is_active', true)->where('models.is_active', true)
            ->select(['models.id as model_id', 'models.provider', 'models.model_key', 'models.capabilities', 'rules.id as routing_rule_id', 'rules.max_retries', 'rules.max_latency_ms', 'rules.fallback_model_ids'])
            ->first();
        if ($rule) {
            $fallbackIds = json_decode($rule->fallback_model_ids ?: '[]', true) ?: [];
            $fallbackById = AiModelPrice::query()->whereIn('id', $fallbackIds)->where('is_active', true)->get(['id', 'provider', 'model_key', 'capabilities'])->keyBy('id');
            $fallbackModels = collect($fallbackIds)->map(fn ($id) => $fallbackById->get((int) $id))->filter(fn ($candidate) => $candidate && $providers->isAvailable($candidate->provider) && $this->supports($candidate->capabilities, $requiredCapabilities) && !$this->circuitOpen($candidate->provider, $candidate->model_key))->values();
            if (!$this->supports($rule->capabilities, $requiredCapabilities) || !$providers->isAvailable($rule->provider)) {
                $replacement = $fallbackModels->first();
                if ($replacement) {
                    $fallbackModels = $fallbackModels->reject(fn ($candidate) => (int) $candidate->id === (int) $replacement->id)->values();
                    return ['provider' => $replacement->provider, 'model' => $replacement->model_key, 'mode' => $mode, 'source' => 'capability_fallback', 'rule_id' => $rule->routing_rule_id, 'max_retries' => (int) $rule->max_retries, 'max_latency_ms' => $rule->max_latency_ms ? (int) $rule->max_latency_ms : null, 'fallback_model_ids' => $fallbackModels->pluck('id')->map(fn ($id) => (int) $id)->all(), 'fallback_models' => $fallbackModels->all()];
                }
                throw new \RuntimeException('The configured AI model does not support the required task capabilities.');
            }
            $primaryHealthy = !$this->circuitOpen($rule->provider, $rule->model_key);
            if (!$primaryHealthy) {
                $replacement = $fallbackModels->first(fn ($candidate) => !$this->circuitOpen($candidate->provider, $candidate->model_key));
                if ($replacement) {
                    $fallbackModels = $fallbackModels->reject(fn ($candidate) => (int) $candidate->id === (int) $replacement->id)->values();
                    return ['provider' => $replacement->provider, 'model' => $replacement->model_key, 'mode' => $mode, 'source' => 'circuit_breaker_fallback', 'rule_id' => $rule->routing_rule_id, 'max_retries' => (int) $rule->max_retries, 'max_latency_ms' => $rule->max_latency_ms ? (int) $rule->max_latency_ms : null, 'fallback_model_ids' => $fallbackModels->pluck('id')->map(fn ($id) => (int) $id)->all(), 'fallback_models' => $fallbackModels->all()];
                }
                throw new \RuntimeException('The configured AI model is temporarily unavailable and has no healthy fallback.');
            }
            return ['provider' => $rule->provider, 'model' => $rule->model_key, 'mode' => $mode, 'source' => 'task_mode_rule', 'rule_id' => $rule->routing_rule_id, 'max_retries' => (int) $rule->max_retries, 'max_latency_ms' => $rule->max_latency_ms ? (int) $rule->max_latency_ms : null, 'fallback_model_ids' => $fallbackModels->pluck('id')->map(fn ($id) => (int) $id)->all(), 'fallback_models' => $fallbackModels->all()];
        }

        $requested = AiModelPrice::query()->where('provider', $requestedProvider)->where('model_key', $requestedModel)->where('is_active', true)->first();
        if ($requested && $providers->isAvailable($requested->provider) && $this->supports($requested->capabilities, $requiredCapabilities)) {
            $fallbacks = $this->configuredFallbacks($requested->provider, $requiredCapabilities);
            return ['provider' => $requested->provider, 'model' => $requested->model_key, 'mode' => $mode, 'source' => 'business_provider_preference', 'rule_id' => null, 'max_retries' => 1, 'max_latency_ms' => null, 'fallback_model_ids' => $fallbacks->pluck('id')->map(fn ($id) => (int) $id)->all(), 'fallback_models' => $fallbacks->all()];
        }

        $provider = (string) config('services.ai.provider', 'groq');
        $model = match ($provider) {
            'groq' => (string) config('services.groq.model', ''),
            'gemini' => (string) config('services.gemini.model', ''),
            'claude' => (string) config('services.claude.model', ''),
            'openai' => (string) config('services.openai.model', 'gpt-4o'),
            default => '',
        };
        $default = $model !== '' ? AiModelPrice::query()->where('provider', $provider)->where('model_key', $model)->where('is_active', true)->first() : null;
        if ($default && $providers->isAvailable($default->provider) && $this->supports($default->capabilities, $requiredCapabilities)) {
            $fallbacks = $this->configuredFallbacks($provider, $requiredCapabilities);
            return ['provider' => $provider, 'model' => $model, 'mode' => $mode, 'source' => 'global_default', 'rule_id' => null, 'max_retries' => 1, 'max_latency_ms' => null, 'fallback_model_ids' => $fallbacks->pluck('id')->map(fn ($id) => (int) $id)->all(), 'fallback_models' => $fallbacks->all()];
        }
        throw new \RuntimeException('No active AI model with configured pricing is available for this task.');
    }

    private function enabledModes(): array
    {
        $modes = DB::table('ai_credit_settings')->value('enabled_modes');
        if (is_string($modes)) $modes = json_decode($modes, true);
        return is_array($modes) && $modes ? $modes : ['auto'];
    }

    private function circuitOpen(string $provider, string $model): bool
    {
        return DB::getSchemaBuilder()->hasTable('ai_provider_health')
            && DB::table('ai_provider_health')->where('provider', $provider)->where('model_key', $model)->where('available_after', '>', now())->exists();
    }

    private function supports($capabilities, array $required): bool
    {
        if (is_string($capabilities)) $capabilities = json_decode($capabilities, true);
        if (!is_array($capabilities) || !$capabilities) return true;
        return count(array_diff($required, $capabilities)) === 0;
    }

    private function configuredFallbacks(string $primaryProvider, array $requiredCapabilities)
    {
        $provider = (string) config('services.ai.fallback_provider', '');
        $model = match ($provider) {
            'groq' => (string) config('services.groq.model', ''),
            'gemini' => (string) config('services.gemini.model', ''),
            'claude' => (string) config('services.claude.model', ''),
            'openai' => (string) config('services.openai.model', 'gpt-4o'),
            default => '',
        };
        if ($provider === '' || $provider === $primaryProvider || $model === '') return collect();
        $candidate = AiModelPrice::query()->where('provider', $provider)->where('model_key', $model)->where('is_active', true)->first();
        return $candidate && app(AiProviderConfiguration::class)->isAvailable($provider) && $this->supports($candidate->capabilities, $requiredCapabilities) && !$this->circuitOpen($provider, $model)
            ? collect([$candidate->only(['id', 'provider', 'model_key'])])
            : collect();
    }
}
