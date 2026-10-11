<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BusinessProfile;
use App\Models\TeamMember;
use App\Services\ActiveBusiness;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BusinessController extends Controller
{
    public function showProfile(Request $request, ActiveBusiness $activeBusiness)
    {
        $business = $activeBusiness->resolve($request, permission: 'read');

        return response()->json($this->profileFields($business));
    }

    public function updateProfile(Request $request, ActiveBusiness $activeBusiness)
    {
        $business = $activeBusiness->resolve($request, permission: 'write');
        $data = $request->validate([
            'business_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'business_type' => ['sometimes', 'nullable', 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'website' => ['sometimes', 'nullable', 'url', 'max:2048'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'address' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'city' => ['sometimes', 'nullable', 'string', 'max:120'],
            'country' => ['sometimes', 'nullable', 'string', 'max:120'],
            'currency' => ['sometimes', 'nullable', 'string', 'size:3'],
            'timezone' => ['sometimes', 'nullable', 'timezone'],
            'services' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'reply_style' => ['sometimes', 'nullable', Rule::in(['friendly', 'professional', 'casual', 'formal'])],
        ]);
        $business->fill($data)->save();

        return response()->json($this->profileFields($business->fresh()));
    }

    public function showGoLiveSettings(Request $request, int $businessId, ActiveBusiness $activeBusiness)
    {
        $business = $activeBusiness->resolve($request, permission: 'read', routeBusinessId: $businessId);
        return response()->json([
            'consent_policy_acknowledged' => $business->consent_policy_acknowledged_at !== null,
            'consent_policy_acknowledged_at' => $business->consent_policy_acknowledged_at,
            'handoff_notification_email' => $business->handoff_notification_email,
        ]);
    }

    public function updateGoLiveSettings(Request $request, int $businessId, ActiveBusiness $activeBusiness)
    {
        $business = $activeBusiness->resolve($request, permission: 'write', routeBusinessId: $businessId);
        $data = $request->validate([
            'consent_policy_acknowledged' => ['sometimes', 'boolean'],
            'handoff_notification_email' => ['sometimes', 'nullable', 'email', 'max:255'],
        ]);
        $updates = [];
        if (array_key_exists('consent_policy_acknowledged', $data)) {
            $updates['consent_policy_acknowledged_at'] = $data['consent_policy_acknowledged'] ? now() : null;
        }
        if (array_key_exists('handoff_notification_email', $data)) {
            $updates['handoff_notification_email'] = $data['handoff_notification_email'];
        }
        $business->forceFill($updates)->save();
        return $this->showGoLiveSettings($request, $businessId, $activeBusiness);
    }

    private function profileFields(BusinessProfile $business): array
    {
        return $business->only([
            'id', 'business_name', 'business_type', 'description', 'website',
            'phone', 'address', 'city', 'country', 'currency', 'timezone', 'services', 'reply_style',
        ]);
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $businesses = BusinessProfile::query()
            ->where('user_id', $user->id)
            ->get()
            ->map(fn (BusinessProfile $business) => $this->present($business, 'owner', $user->business_id));

        $memberships = TeamMember::query()
            ->with('business')
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->get()
            ->filter(fn (TeamMember $membership) => $membership->business !== null)
            ->map(fn (TeamMember $membership) => $this->present($membership->business, $membership->role, $user->business_id));

        return response()->json([
            'businesses' => $businesses->concat($memberships)->unique('id')->values(),
        ]);
    }

    private function present(BusinessProfile $business, string $role, ?int $primaryBusinessId): array
    {
        return [
            'id' => $business->id,
            'name' => $business->business_name ?: $business->name,
            'role' => $role,
            'is_primary' => (int) $business->id === (int) $primaryBusinessId,
        ];
    }
}
