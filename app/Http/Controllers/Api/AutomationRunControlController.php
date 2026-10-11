<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class AutomationRunControlController extends Controller
{
    public function show(Request $request, int $businessId)
    {
        $business = app(\App\Services\ActiveBusiness::class)->resolve(
            $request, permission: 'automation_read', routeBusinessId: $businessId
        );

        return response()->json([
            'business_id' => $business->id,
            'paused' => $business->automation_paused_at !== null,
            'paused_at' => $business->automation_paused_at?->toISOString(),
        ]);
    }

    public function update(Request $request, int $businessId)
    {
        $validated = $request->validate(['paused' => ['required', 'boolean']]);
        $business = app(\App\Services\ActiveBusiness::class)->resolve(
            $request, permission: 'automation_write', routeBusinessId: $businessId
        );
        $business = app(\App\Services\AutomationRunControl::class)->setBusinessPaused($business, (bool) $validated['paused']);

        return response()->json([
            'business_id' => $business->id,
            'paused' => $business->automation_paused_at !== null,
            'paused_at' => $business->automation_paused_at?->toISOString(),
        ]);
    }
}
