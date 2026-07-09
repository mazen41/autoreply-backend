<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BusinessProfile;
use Illuminate\Http\Request;

class OnboardingController extends Controller
{
    /** Get or create this user's profile (one per user) */
    private function profile(Request $request): BusinessProfile
    {
        return BusinessProfile::firstOrCreate(['user_id' => $request->user()->id]);
    }

    /** STEP 1 — business type */
    public function step1(Request $request)
    {
        $validated = $request->validate([
            'business_type' => 'required|string|max:50',
        ]);

        $this->profile($request)->update($validated);

        return response()->json(['message' => 'Step 1 saved']);
    }

    /** STEP 2 — business info + schedule */
    public function step2(Request $request)
    {
        $validated = $request->validate([
            'business_name' => 'required|string|max:255',
            'phone'         => 'nullable|string|max:30',
            'city'          => 'nullable|string|max:100',
            'country'       => 'nullable|string|max:100',
            'working_days'  => 'nullable|array',
            'working_days.*'=> 'string|max:10',
            'working_from'  => 'nullable|string|max:5',
            'working_to'    => 'nullable|string|max:5',
        ]);

        $this->profile($request)->update($validated);

        return response()->json(['message' => 'Step 2 saved']);
    }

    /** STEP 3 — AI brain (services, FAQs, reply style) */
    public function step3(Request $request)
    {
        $validated = $request->validate([
            'services'    => 'nullable|string',
            'faqs'        => 'nullable|array',
            'faqs.*.q'    => 'nullable|string|max:500',
            'faqs.*.a'    => 'nullable|string|max:1000',
            'reply_style' => 'nullable|string|max:50',
        ]);

        $this->profile($request)->update($validated);

        return response()->json(['message' => 'Step 3 saved']);
    }

    /** STEP 4 — connected channel */
    public function step4(Request $request)
    {
        $validated = $request->validate([
            'connected_channel' => 'nullable|string|max:50',
        ]);

        $this->profile($request)->update($validated);

        return response()->json(['message' => 'Step 4 saved']);
    }

    /** COMPLETE — mark onboarding done, return fresh user */
    public function complete(Request $request)
    {
        $user = $request->user();
        $user->update(['onboarding_completed' => true]);

        return response()->json([
            'message' => 'Onboarding complete',
            'user'    => $user->fresh(),
        ]);
    }
}
