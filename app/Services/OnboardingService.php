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
    public function updateProgress(int $userId, string $step): OnboardingProgress
    {
        $progress = OnboardingProgress::firstOrCreate(
            ['user_id' => $userId],
            [
                'step' => 'welcome',
                'progress_percentage' => 0,
                'completed_steps' => [],
            ]
        );

        $progress->advanceStep($step);

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
        $this->updateProgress($userId, 'connect_channel');
    }

    /**
     * Complete onboarding step: Add Business Info
     */
    public function completeBusinessInfo(int $userId, int $businessId): void
    {
        $progress = $this->updateProgress($userId, 'business_info');
        $progress->update(['business_id' => $businessId]);
    }

    /**
     * Complete onboarding step: Enable AI
     */
    public function completeEnableAI(int $userId): void
    {
        $this->updateProgress($userId, 'enable_ai');
    }

    /**
     * Complete onboarding step: Send Test Message
     */
    public function completeTestMessage(int $userId): void
    {
        $this->updateProgress($userId, 'test_message');
    }

    /**
     * Complete onboarding step: Complete Setup
     */
    public function completeSetup(int $userId): void
    {
        $this->updateProgress($userId, 'complete');
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

        $nextStep = $this->getNextStep($progress->step);

        return [
            'completed' => $progress->isCompleted(),
            'step' => $progress->step,
            'step_name' => $steps[$progress->step] ?? $progress->step,
            'progress_percentage' => $progress->progress_percentage,
            'completed_steps' => $progress->completed_steps ?? [],
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
    public function skipOnboarding(int $userId): void
    {
        OnboardingProgress::updateOrCreate(
            ['user_id' => $userId],
            [
                'step' => 'complete',
                'progress_percentage' => 100,
                'completed_steps' => ['welcome', 'connect_channel', 'business_info', 'enable_ai', 'test_message', 'complete'],
                'completed_at' => now(),
            ]
        );

        Log::info('Onboarding skipped', ['user_id' => $userId]);
    }
}
