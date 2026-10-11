<?php

namespace App\Services;

use App\Models\BusinessProfile;
use App\Models\TeamMember;
use App\Models\User;

class BusinessAccessService
{
    /**
     * Resolve a business only when the user owns it or has an active team
     * membership with the requested permission. Conceal foreign businesses.
     */
    public function authorize(User $user, int|string $businessId, string $permission = 'read', bool $hideUnauthorized = true): BusinessProfile
    {
        $business = BusinessProfile::findOrFail($businessId);
        if ((int) $business->user_id === (int) $user->id) {
            return $business;
        }

        $membership = TeamMember::query()
            ->where('business_id', $business->id)
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->first();

        if (!$membership) {
            abort($hideUnauthorized ? 404 : 403);
        }
        if (!$membership->hasPermission($permission)) {
            abort(403);
        }

        return $business;
    }
}
