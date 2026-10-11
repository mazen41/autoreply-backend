<?php

namespace App\Services;

use App\Models\BusinessProfile;
use App\Models\User;
use Illuminate\Http\Request;

class ActiveBusiness
{
    public function __construct(private readonly BusinessAccessService $access)
    {
    }

    /** Resolve only the explicitly selected business; never fall back to users.business_id. */
    public function resolve(Request $request, ?User $user = null, string $permission = 'read', mixed $routeBusinessId = null): BusinessProfile
    {
        $user ??= $request->user();
        abort_unless($user, 401);

        $businessId = $routeBusinessId
            ?? $request->header('X-Business-Id')
            ?? $request->input('business_id');

        if (!$businessId || !filter_var($businessId, FILTER_VALIDATE_INT) || (int) $businessId < 1) {
            abort(422, 'Select a business before continuing.');
        }

        return $this->access->authorize($user, (int) $businessId, $permission, hideUnauthorized: false);
    }
}
