<?php

namespace App\Services;

use App\Models\BusinessProfile;
use App\Models\Bot;
use App\Models\Channel;

class AiSetupVerificationService
{
    /** Return the readiness checks used by both the checklist and AI activation. */
    public function checklist(BusinessProfile $business): array
    {
        $connectedChannelQuery = $business->channels()->where('status', 'connected');
        $connectedChannelIds = (clone $connectedChannelQuery)->pluck('id');

        $provider = strtolower((string) ($business->ai_provider ?: config('services.ai.provider')));
        $providerKey = $this->providerKey($provider);
        $activeConnectedAgent = $business->bots()->where('status', 'active')
            ->whereHas('channels', fn ($query) => $query->whereIn('channels.id', $connectedChannelIds))
            ->get(['id', 'ai_provider'])
            ->contains(fn (Bot $bot) => filled($this->providerKey(strtolower((string) ($bot->ai_provider ?: $provider)))));

        $hasInstructions = filled($business->ai_instructions)
            || filled($business->knowledge_base)
            || $business->knowledgeFiles()->whereIn('status', ['processed', 'completed'])->exists()
            || $business->bots()->where('status', 'active')->whereNotNull('ai_instructions')
                ->where('ai_instructions', '!=', '')->exists();

        $checks = [
            'connected_channel' => [
                'passed' => $connectedChannelIds->isNotEmpty(),
                'label' => 'Connect at least one channel',
            ],
            'active_agent' => [
                'passed' => $activeConnectedAgent,
                'label' => 'Create an active agent with a supported provider and assign a connected channel',
            ],
            'provider_configured' => [
                'passed' => $providerKey !== null && trim((string) $providerKey) !== '',
                'label' => 'Configure a supported AI provider',
            ],
            'knowledge_or_instructions' => [
                'passed' => $hasInstructions,
                'label' => 'Add business knowledge or AI instructions',
            ],
            'test_reply_generated' => [
                'passed' => $business->ai_setup_tested_at !== null
                    && is_string($business->ai_setup_tested_config_hash)
                    && hash_equals($this->configurationFingerprint($business), $business->ai_setup_tested_config_hash),
                'label' => 'Generate a successful test reply using the current agent setup',
            ],
            'consent_policy_acknowledged' => [
                'passed' => $business->consent_policy_acknowledged_at !== null,
                'label' => 'Acknowledge the marketing consent and opt-out policy',
            ],
            'handoff_recipient_configured' => [
                'passed' => filter_var($business->handoff_notification_email, FILTER_VALIDATE_EMAIL) !== false,
                'label' => 'Set an email recipient for human handoffs',
            ],
        ];

        return [
            'ready' => collect($checks)->every(fn (array $check) => $check['passed']),
            'checks' => $checks,
            'missing' => collect($checks)->filter(fn (array $check) => !$check['passed'])
                ->map(fn (array $check) => $check['label'])->values()->all(),
            'provider' => $provider,
        ];
    }

    public function configurationFingerprint(BusinessProfile $business): string
    {
        $business->loadMissing('knowledgeFiles');
        $bots = $business->bots()->orderBy('id')->get([
            'id', 'status', 'ai_provider', 'ai_model', 'ai_instructions', 'ai_tone_style', 'reply_style',
        ]);
        $botChannels = [];
        foreach ($bots as $bot) {
            $botChannels[$bot->id] = $bot->channels()->orderBy('channels.id')->pluck('channels.id')->all();
        }
        $channels = Channel::query()->where('business_id', $business->id)->where('status', 'connected')
            ->orderBy('id')->get(['id', 'type', 'status'])->toArray();
        $knowledgeFiles = $business->knowledgeFiles()->orderBy('id')->get(['id', 'status', 'updated_at'])
            ->map(fn ($file) => [$file->id, $file->status, $file->updated_at?->toISOString()])->all();

        return hash('sha256', json_encode([
            'business' => [$business->ai_provider, $business->ai_model, $business->ai_instructions, $business->knowledge_base],
            'bots' => $bots->toArray(),
            'bot_channels' => $botChannels,
            'channels' => $channels,
            'knowledge_files' => $knowledgeFiles,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function providerKey(string $provider): ?string
    {
        return match ($provider) {
            'gemini' => config('services.gemini.api_key'),
            'groq' => config('services.groq.api_key'),
            default => null,
        };
    }
}
