<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BusinessFeatureFlagAssignment;
use App\Models\FeatureFlag;
use App\Services\ActiveBusiness;
use App\Services\AuditLoggerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BusinessFeatureFlagController extends Controller
{
    public function index(Request $request, int $businessId)
    {
        $business = app(ActiveBusiness::class)->resolve($request, permission: 'automation_read', routeBusinessId: $businessId);
        $flags = FeatureFlag::query()
            ->where(fn ($query) => $query->where('is_globally_enabled', true)->orWhere('requires_business_opt_in', true))
            ->orderBy('key')
            ->get();

        return response()->json([
            'business_id' => $business->id,
            'flags' => $flags->map(fn (FeatureFlag $flag) => [
                'key' => $flag->key,
                'name' => $flag->name,
                'description' => $flag->description,
                'enabled' => $flag->isEnabledForBusiness($business->id),
                'global' => $flag->is_globally_enabled,
            ])->values(),
        ]);
    }

    public function update(Request $request, int $businessId, string $key)
    {
        $validated = $request->validate(['enabled' => ['required', 'boolean']]);
        $business = app(ActiveBusiness::class)->resolve($request, permission: 'automation_write', routeBusinessId: $businessId);
        $flag = FeatureFlag::query()->where('key', $key)->where('requires_business_opt_in', true)->firstOrFail();
        $enabled = (bool) $validated['enabled'];

        DB::transaction(function () use ($business, $flag, $enabled, $request): void {
            BusinessFeatureFlagAssignment::query()->updateOrCreate(
                ['business_id' => $business->id, 'feature_flag_id' => $flag->id],
                [
                    'enabled' => $enabled,
                    'changed_by' => $request->user()->id,
                    'enabled_at' => $enabled ? now() : null,
                ]
            );

            AuditLoggerService::log(
                $enabled ? 'feature_flag.enabled' : 'feature_flag.disabled',
                $flag,
                ['feature_key' => $flag->key, 'enabled' => $enabled],
                (int) $request->user()->id,
                (int) $business->id,
            );
        });

        return response()->json([
            'business_id' => $business->id,
            'key' => $flag->key,
            'enabled' => $enabled,
        ]);
    }
}
