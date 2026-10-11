<?php

namespace App\Services;

use App\Models\OnboardingProgress;
use App\Models\User;
use App\Models\BusinessProfile;
use App\Models\Channel;
use Illuminate\Support\Facades\Log;

class OnboardingService
{
    /**
     * Initialize onboarding for a new user
     */
    public function initializeOnboarding(int $userId): OnboardingProgress
    {
        return OnboardingProgress::create([
            'user_id' => $userId,
            'step' => 'welcome',
            'progress_percentage' => 0,
            'completed_steps' => [],
        ]);
    }

    /**
     * Update onboarding progress
     */
    public function updateProgress(int $userId, string $step, ?BusinessProfile $business = null): OnboardingProgress
    {
        if (!in_array($step, ['step1', 'step2', 'step3', 'step4'], true)) {
            throw \Illuminate\Validation\ValidationException::withMessages(['step' => 'This onboarding step cannot be marked complete directly.']);
        }

        $business ??= BusinessProfile::where('user_id', $userId)->first();
        $verified = match ($step) {
            'step1' => filled($business?->business_type),
            'step2' => filled($business?->business_name),
            'step3' => $business !== null,
            'step4' => $business?->channels()->where('status', 'connected')->exists() ?? false,
        };
        if (!$verified) {
            throw \Illuminate\Validation\ValidationException::withMessages(['step' => 'The onboarding step has not been verified yet.']);
        }

        $progress = OnboardingProgress::firstOrCreate(
            ['user_id' => $userId],
            [
                'step' => 'welcome',
                'progress_percentage' => 0,
                'completed_steps' => [],
            ]
        );

        $progress->advanceStep($step);
        if ($business) {
            $progress->forceFill(['business_id' => $business->id])->save();
        }

        Log::info('Onboarding progress updated', [
            'user_id' => $userId,
            'step' => $step,
            'progress' => $progress->progress_percentage,
        ]);

        return $progress;
    }

    /**
     * Complete onboarding step: Connect Channel
     */
    public function completeConnectChannel(int $userId): void
    {
        $business = BusinessProfile::where('user_id', $userId)->first();
        $this->assertConnectedChannel($business);
        $this->recordVerifiedStep($userId, 'connect_channel', $business?->id);
    }

    /**
     * Complete onboarding step: Add Business Info
     */
    public function completeBusinessInfo(int $userId, int $businessId): void
    {
        $business = BusinessProfile::where('user_id', $userId)->findOrFail($businessId);
        if (!filled($business->business_name)) {
            throw \Illuminate\Validation\ValidationException::withMessages(['step' => 'Save the business name before completing this step.']);
        }
        $this->recordVerifiedStep($userId, 'business_info', $business->id);
    }

    /**
     * Complete onboarding step: Enable AI
     */
    public function completeEnableAI(int $userId): void
    {
        $business = BusinessProfile::where('user_id', $userId)->firstOrFail();
        $this->assertAiReady($business);
        $this->recordVerifiedStep($userId, 'enable_ai', $business->id);
    }

    /**
     * Complete onboarding step: Send Test Message
     */
    public function completeTestMessage(int $userId): void
    {
        $business = BusinessProfile::where('user_id', $userId)->firstOrFail();
        $this->assertAiReady($business);
        $this->recordVerifiedStep($userId, 'test_message', $business->id);
    }

    /**
     * Complete onboarding step: Complete Setup
     */
    public function completeSetup(int $userId, ?BusinessProfile $business = null): array
    {
        $business ??= BusinessProfile::where('user_id', $userId)->first();
        $readiness = $business
            ? app(AiSetupVerificationService::class)->checklist($business)
            : ['ready' => false, 'checks' => [], 'missing' => ['Create and configure a business agent.']];

        // Dismissing the profile wizard is not the same as completing setup.
        // Keep the checklist incomplete until a connected agent passes live AI verification.
        User::whereKey($userId)->update(['onboarding_completed' => true]);
        if (!$readiness['ready']) {
            $progress = OnboardingProgress::firstOrCreate(
                ['user_id' => $userId],
                ['step' => 'welcome', 'progress_percentage' => 0, 'completed_steps' => []]
            );
            $progress->forceFill([
                'step' => 'profile_saved',
                'business_id' => $business?->id,
                'completed_at' => null,
            ])->save();
            return $readiness;
        }

        $this->recordVerifiedStep($userId, 'complete', $business?->id, forceComplete: true);
        return $readiness;
    }

    /**
     * Get onboarding status for a user
     */
    public function getOnboardingStatus(int $userId): array
    {
        $progress = OnboardingProgress::where('user_id', $userId)->first();

        if (!$progress) {
            return [
                'completed' => false,
                'step' => 'welcome',
                'progress_percentage' => 0,
                'completed_steps' => [],
                'next_step' => 'connect_channel',
                'skipped' => false,
                'setup_ready' => false,
            ];
        }

        $steps = [
            'welcome' => 'Welcome',
            'connect_channel' => 'Connect Channel',
            'business_info' => 'Add Business Info',
            'enable_ai' => 'Enable AI',
            'test_message' => 'Send Test Message',
            'complete' => 'Complete Setup',
        ];

        $business = BusinessProfile::where('user_id', $userId)->first();
        $readiness = $business
            ? app(AiSetupVerificationService::class)->checklist($business)
            : ['ready' => false, 'checks' => [], 'missing' => ['Create a business and connect a channel.']];
        $verifiedSteps = $this->verifiedSteps($userId, $business, $progress->completed_steps ?? [], $readiness['ready']);
        $skipped = $progress->step === 'skipped';
        $nextStep = $this->getNextStep($progress->step);

        return [
            'completed' => !$skipped && $progress->isCompleted() && $readiness['ready'],
            'skipped' => $skipped,
            'wizard_dismissed' => (bool) User::whereKey($userId)->value('onboarding_completed'),
            'setup_ready' => (bool) $readiness['ready'],
            'setup_checklist' => $readiness['checks'],
            'setup_missing' => $readiness['missing'],
            'step' => $progress->step,
            'step_name' => $steps[$progress->step] ?? $progress->step,
            'progress_percentage' => $progress->progress_percentage,
            'completed_steps' => $verifiedSteps,
            'next_step' => $nextStep,
            'next_step_name' => $steps[$nextStep] ?? $nextStep,
        ];
    }

    /**
     * Get next onboarding step
     */
    private function getNextStep(string $currentStep): string
    {
        $steps = [
            'welcome' => 'connect_channel',
            'connect_channel' => 'business_info',
            'business_info' => 'enable_ai',
            'enable_ai' => 'test_message',
            'test_message' => 'complete',
            'complete' => 'complete',
        ];

        return $steps[$currentStep] ?? 'complete';
    }

    /**
     * Check if user has completed specific step
     */
    public function hasCompletedStep(int $userId, string $step): bool
    {
        $progress = OnboardingProgress::where('user_id', $userId)->first();

        if (!$progress) {
            return false;
        }

        return in_array($step, $progress->completed_steps ?? []);
    }

    /**
     * Skip onboarding
     */
    public function skipOnboarding(int $userId, ?BusinessProfile $business = null): void
    {
        $business ??= BusinessProfile::where('user_id', $userId)->first();
        if ($business) {
            $business->channels()->update(['ai_enabled' => false]);
        }
        User::whereKey($userId)->update(['onboarding_completed' => true]);
        $progress = OnboardingProgress::firstOrCreate(
            ['user_id' => $userId],
            ['step' => 'welcome', 'progress_percentage' => 0, 'completed_steps' => []]
        );
        $progress->forceFill([
            'step' => 'skipped',
            'business_id' => $business?->id,
            'completed_at' => null,
        ])->save();

        Log::info('Onboarding skipped', ['user_id' => $userId]);
    }

    public function completeVerifiedStep(int $userId, string $step, BusinessProfile $business): void
    {
        match ($step) {
            'connect_channel' => $this->assertConnectedChannel($business),
            'business_info' => filled($business->business_name)
                ? true : throw \Illuminate\Validation\ValidationException::withMessages(['step' => 'Save the business name first.']),
            'enable_ai', 'test_message' => $this->assertAiReady($business),
            'complete' => $this->assertAiReady($business),
            default => throw \Illuminate\Validation\ValidationException::withMessages(['step' => 'Unknown onboarding step.']),
        };
        $this->recordVerifiedStep($userId, $step, $business->id, forceComplete: $step === 'complete');
    }

    private function assertConnectedChannel(?BusinessProfile $business): void
    {
        if (!$business || !$business->channels()->where('status', 'connected')->exists()) {
            throw \Illuminate\Validation\ValidationException::withMessages(['step' => 'Connect at least one channel before completing this step.']);
        }
    }

    private function assertAiReady(BusinessProfile $business): void
    {
        $readiness = app(AiSetupVerificationService::class)->checklist($business);
        if (!$readiness['ready']) {
            throw \Illuminate\Validation\ValidationException::withMessages(['setup' => $readiness['missing']]);
        }
    }

    private function recordVerifiedStep(int $userId, string $step, ?int $businessId, bool $forceComplete = false): OnboardingProgress
    {
        $progress = OnboardingProgress::firstOrCreate(
            ['user_id' => $userId],
            ['step' => 'welcome', 'progress_percentage' => 0, 'completed_steps' => []]
        );
        $steps = array_values(array_unique(array_merge($progress->completed_steps ?? [], [$step])));
        $progress->forceFill([
            'step' => $step,
            'business_id' => $businessId,
            'completed_steps' => $steps,
            'progress_percentage' => $forceComplete ? 100 : min(90, (int) round((count($steps) / 6) * 100)),
            'completed_at' => $forceComplete ? now() : null,
        ])->save();
        return $progress;
    }

    private function verifiedSteps(int $userId, ?BusinessProfile $business, array $stored, bool $ready): array
    {
        $verified = [];
        if (filled($business?->business_type) && in_array('step1', $stored, true)) $verified[] = 'step1';
        if (filled($business?->business_name) && in_array('step2', $stored, true)) $verified[] = 'step2';
        if ($business && in_array('step3', $stored, true)) $verified[] = 'step3';
        $connected = $business?->channels()->where('status', 'connected')->exists() ?? false;
        if ($connected) {
            foreach (['step4', 'connect_channel'] as $step) if (in_array($step, $stored, true)) $verified[] = $step;
        }
        if ($ready) foreach (['business_info', 'enable_ai', 'test_message', 'complete'] as $step) if (in_array($step, $stored, true)) $verified[] = $step;
        return array_values(array_unique($verified));
    }
}
