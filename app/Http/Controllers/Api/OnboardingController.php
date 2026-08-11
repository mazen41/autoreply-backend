<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\OnboardingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class OnboardingController extends Controller
{
    private $onboardingService;

    public function __construct(OnboardingService $onboardingService)
    {
        $this->onboardingService = $onboardingService;
    }

    /**
     * Get onboarding status
     */
    public function getStatus(Request $request)
    {
        $status = $this->onboardingService->getOnboardingStatus(Auth::id());

        return response()->json($status);
    }

    /**
     * Update onboarding progress
     */
    public function updateProgress(Request $request)
    {
        $request->validate([
            'step' => 'required|string',
        ]);

        $progress = $this->onboardingService->updateProgress(Auth::id(), $request->step);

        return response()->json(['success' => true, 'progress' => $progress]);
    }

    /**
     * Complete specific onboarding step
     */
    public function completeStep(Request $request)
    {
        $request->validate([
            'step' => 'required|string|in:connect_channel,business_info,enable_ai,test_message,complete',
            'business_id' => 'nullable|integer|exists:business_profiles,id',
        ]);

        $step = $request->step;
        $businessId = $request->business_id;

        switch ($step) {
            case 'connect_channel':
                $this->onboardingService->completeConnectChannel(Auth::id());
                break;
            case 'business_info':
                if ($businessId) {
                    $this->onboardingService->completeBusinessInfo(Auth::id(), $businessId);
                }
                break;
            case 'enable_ai':
                $this->onboardingService->completeEnableAI(Auth::id());
                break;
            case 'test_message':
                $this->onboardingService->completeTestMessage(Auth::id());
                break;
            case 'complete':
                $this->onboardingService->completeSetup(Auth::id());
                break;
        }

        $status = $this->onboardingService->getOnboardingStatus(Auth::id());

        return response()->json(['success' => true, 'status' => $status]);
    }

    /**
     * Skip onboarding
     */
    public function skip(Request $request)
    {
        $this->onboardingService->skipOnboarding(Auth::id());

        return response()->json(['success' => true]);
    }

    /**
     * Initialize onboarding for user
     */
    public function initialize(Request $request)
    {
        $progress = $this->onboardingService->initializeOnboarding(Auth::id());

        return response()->json(['success' => true, 'progress' => $progress]);
    }
}
