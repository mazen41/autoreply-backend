<?php

namespace App\Http\Middleware;

use App\Models\FeatureFlag;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckFeatureFlag
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, string $flagKey): Response
    {
        $user = $request->user();
        $businessId = $user?->business_id;

        // Check if the feature flag exists and is enabled
        $flag = FeatureFlag::forKey($flagKey)->first();

        if (!$flag) {
            return response()->json([
                'error' => 'Feature not found',
                'error_code' => 'FEATURE_NOT_FOUND',
                'feature_key' => $flagKey,
            ], 404);
        }

        if (!$flag->isEnabledForBusiness($businessId)) {
            return response()->json([
                'error' => 'Feature Not Enabled On Your Workspace',
                'error_code' => 'FEATURE_NOT_ENABLED',
                'feature_key' => $flagKey,
                'feature_name' => $flag->name,
                'upgrade_url' => '/dashboard/settings/billing',
            ], 403);
        }

        return $next($request);
    }
}
