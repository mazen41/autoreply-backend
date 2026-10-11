<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BusinessProfile;
use App\Models\Channel;
use App\Services\OnboardingService;
use App\Services\ActiveBusiness;
use App\Models\TeamMember;
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
        $selectedBusinessId = $request->header('X-Business-Id') ?? $request->input('business_id');
        if ($selectedBusinessId) {
            return app(ActiveBusiness::class)->resolve(
                $request,
                permission: 'write',
                routeBusinessId: $selectedBusinessId,
            );
        }

        if (TeamMember::query()->where('user_id', $request->user()->id)->where('is_active', true)->exists()) {
            abort(403, 'Select the business you are onboarding.');
        }

        $profile = BusinessProfile::firstOrCreate(
            ['user_id' => $request->user()->id],
            [
                'name' => $request->user()->name . "'s Business",
                'auto_order_confirmation_enabled' => false,
            ]
        );

        // business_id is the user's selected/primary tenant. A team member's
        // onboarding profile lookup must not silently move them out of the
        // business they already belong to.
        if (empty($request->user()->business_id)) {
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
            'step' => 'required|string|in:step1,step2,step3,step4',
        ]);

        $profile = $this->profile($request);
        abort_unless((int) $profile->user_id === (int) Auth::id(), 403, 'Only the business owner can update personal onboarding progress.');
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

        $business = $this->profile($request);
        abort_unless((int) $business->user_id === (int) Auth::id(), 403);
        if ($businessId && (int) $businessId !== (int) $business->id) {
            abort(403, 'The selected onboarding business does not match the authorized business.');
        }
        $this->onboardingService->completeVerifiedStep(Auth::id(), $step, $business);

        $status = $this->onboardingService->getOnboardingStatus(Auth::id());

        return response()->json(['success' => true, 'status' => $status]);
    }

    /**
     * Skip onboarding
     */
    public function skip(Request $request)
    {
        $business = $this->profile($request);
        abort_unless((int) $business->user_id === (int) Auth::id(), 403, 'Only the business owner can skip onboarding.');
        $this->onboardingService->skipOnboarding(Auth::id(), $business);

        return response()->json(['success' => true, 'completed' => false, 'skipped' => true]);
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

        $this->onboardingService->updateProgress(Auth::id(), 'step1', $profile);

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
            'currency'      => 'nullable|string|size:3',
            'timezone'      => ['nullable', 'string', 'timezone'],
            'working_days'  => 'nullable|array',
            'working_from'  => 'nullable|string|max:10',
            'working_to'    => 'nullable|string|max:10',
        ]);

        $profile = $this->profile($request);
        $regionalDefaults = $this->regionalDefaults((string) ($request->country ?? ''));
        $currency = strtoupper((string) ($request->input('currency') ?: $regionalDefaults['currency']));
        $timezone = $request->input('timezone') ?: $regionalDefaults['timezone'];
        $profile->update(array_merge(
            $request->only(['business_name', 'phone', 'city', 'country', 'working_days', 'working_from', 'working_to']),
            ['name' => $request->business_name, 'currency' => $currency, 'timezone' => $timezone]
        ));

        $this->onboardingService->updateProgress(Auth::id(), 'step2', $profile);

        return response()->json(['success' => true]);
    }

    private function regionalDefaults(string $country): array
    {
        $value = mb_strtolower(trim($country));
        $countries = [
            'sa' => ['currency' => 'SAR', 'timezone' => 'Asia/Riyadh'],
            'saudi arabia' => ['currency' => 'SAR', 'timezone' => 'Asia/Riyadh'],
            'السعودية' => ['currency' => 'SAR', 'timezone' => 'Asia/Riyadh'],
            'مصر' => ['currency' => 'EGP', 'timezone' => 'Africa/Cairo'],
            'egypt' => ['currency' => 'EGP', 'timezone' => 'Africa/Cairo'],
            'eg' => ['currency' => 'EGP', 'timezone' => 'Africa/Cairo'],
            'الإمارات' => ['currency' => 'AED', 'timezone' => 'Asia/Dubai'],
            'united arab emirates' => ['currency' => 'AED', 'timezone' => 'Asia/Dubai'],
            'الكويت' => ['currency' => 'KWD', 'timezone' => 'Asia/Kuwait'],
            'kuwait' => ['currency' => 'KWD', 'timezone' => 'Asia/Kuwait'],
            'قطر' => ['currency' => 'QAR', 'timezone' => 'Asia/Qatar'],
            'qatar' => ['currency' => 'QAR', 'timezone' => 'Asia/Qatar'],
            'البحرين' => ['currency' => 'BHD', 'timezone' => 'Asia/Bahrain'],
            'bahrain' => ['currency' => 'BHD', 'timezone' => 'Asia/Bahrain'],
            'عمان' => ['currency' => 'OMR', 'timezone' => 'Asia/Muscat'],
            'oman' => ['currency' => 'OMR', 'timezone' => 'Asia/Muscat'],
            'الأردن' => ['currency' => 'JOD', 'timezone' => 'Asia/Amman'],
            'jordan' => ['currency' => 'JOD', 'timezone' => 'Asia/Amman'],
            'المغرب' => ['currency' => 'MAD', 'timezone' => 'Africa/Casablanca'],
            'morocco' => ['currency' => 'MAD', 'timezone' => 'Africa/Casablanca'],
            'تونس' => ['currency' => 'TND', 'timezone' => 'Africa/Tunis'],
            'tunisia' => ['currency' => 'TND', 'timezone' => 'Africa/Tunis'],
        ];

        return $countries[$value] ?? ['currency' => 'USD', 'timezone' => 'UTC'];
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

        $this->onboardingService->updateProgress(Auth::id(), 'step3', $profile);

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

        $this->onboardingService->updateProgress(Auth::id(), 'step4', $profile);

        return response()->json(['success' => true]);
    }

    /**
     * Wizard completion
     */
    public function complete(Request $request)
    {
        $business = $this->profile($request);
        abort_unless((int) $business->user_id === (int) Auth::id(), 403, 'Only the business owner can complete onboarding.');
        $readiness = $this->onboardingService->completeSetup(Auth::id(), $business);

        return response()->json([
            'success' => true,
            'completed' => (bool) $readiness['ready'],
            'setup_ready' => (bool) $readiness['ready'],
            'setup_missing' => $readiness['missing'],
        ]);
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
