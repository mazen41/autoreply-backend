<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BusinessProfile;
use App\Models\Channel;
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

    /** Get or create the BusinessProfile for the current user, and make sure
     *  users.business_id + any of this user's channels stay linked to it. */
    private function profile(Request $request): BusinessProfile
    {
        $profile = BusinessProfile::firstOrCreate(
            ['user_id' => $request->user()->id],
            ['name' => $request->user()->name . "'s Business"]
        );

        if ($request->user()->business_id !== $profile->id) {
            $request->user()->update(['business_id' => $profile->id]);
        }

        Channel::where('user_id', $request->user()->id)
            ->whereNull('business_id')
            ->update(['business_id' => $profile->id]);

        return $profile;
    }

    /**
     * Get onboarding status
     */
    public function getStatus(Request $request)
    {
        $status = $this->onboardingService->getOnboardingStatus(Auth::id());
        $business = BusinessProfile::where('user_id', Auth::id())->first();
        $status['business'] = $business;

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

    /**
     * Wizard Step 1 — business type
     */
    public function step1(Request $request)
    {
        $request->validate([
            'business_type' => 'required|string|max:255',
        ]);

        $profile = $this->profile($request);
        $profile->update(['business_type' => $request->business_type]);

        $this->onboardingService->updateProgress(Auth::id(), 'step1');

        return response()->json(['success' => true, 'business_id' => $profile->id]);
    }

    /**
     * Wizard Step 2 — business info (name, contact, hours)
     */
    public function step2(Request $request)
    {
        $request->validate([
            'business_name' => 'required|string|max:255',
            'phone'         => 'nullable|string|max:50',
            'city'          => 'nullable|string|max:255',
            'country'       => 'nullable|string|max:255',
            'working_days'  => 'nullable|array',
            'working_from'  => 'nullable|string|max:10',
            'working_to'    => 'nullable|string|max:10',
        ]);

        $profile = $this->profile($request);
        $profile->update(array_merge(
            $request->only(['business_name', 'phone', 'city', 'country', 'working_days', 'working_from', 'working_to']),
            ['name' => $request->business_name]
        ));

        $this->onboardingService->updateProgress(Auth::id(), 'step2');

        return response()->json(['success' => true]);
    }

    /**
     * Wizard Step 3 — AI tuning (services, FAQs, reply style)
     */
    public function step3(Request $request)
    {
        $request->validate([
            'services'    => 'nullable|string',
            'faqs'        => 'nullable|array',
            'reply_style' => 'nullable|string|max:255',
        ]);

        $profile = $this->profile($request);
        $profile->update($request->only(['services', 'faqs', 'reply_style']));

        $this->onboardingService->updateProgress(Auth::id(), 'step3');

        return response()->json(['success' => true]);
    }

    /**
     * Wizard Step 4 — connected channel selection
     */
    public function step4(Request $request)
    {
        $request->validate([
            'connected_channel' => 'nullable|string|max:255',
        ]);

        $profile = $this->profile($request);
        $profile->update($request->only(['connected_channel']));

        $this->onboardingService->updateProgress(Auth::id(), 'step4');

        return response()->json(['success' => true]);
    }

    /**
     * Wizard completion
     */
    public function complete(Request $request)
    {
        $this->profile($request);
        $this->onboardingService->updateProgress(Auth::id(), 'complete');
        $this->onboardingService->completeSetup(Auth::id());
        $request->user()->update(['onboarding_completed' => true]);

        return response()->json(['success' => true]);
    }

    /**
     * Upload a knowledge file (PDF/Excel) during onboarding step 3.
     * Delegates the actual extraction to KnowledgeController so both
     * entry points share one implementation.
     */
    public function uploadKnowledgeFile(Request $request)
    {
        return app(KnowledgeController::class)->upload($request);
    }
}
