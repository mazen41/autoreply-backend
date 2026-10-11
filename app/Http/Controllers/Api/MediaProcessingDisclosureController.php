<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ActiveBusiness;
use Illuminate\Http\Request;

class MediaProcessingDisclosureController extends Controller
{
    public function show(Request $request, ActiveBusiness $activeBusiness)
    {
        $business = $activeBusiness->resolve($request, permission: 'read');

        return response()->json([
            'acknowledged' => $business->media_processing_disclosed_at !== null,
            'acknowledged_at' => $business->media_processing_disclosed_at,
        ]);
    }

    public function update(Request $request, ActiveBusiness $activeBusiness)
    {
        $validated = $request->validate(['acknowledged' => ['required', 'boolean']]);
        $business = $activeBusiness->resolve($request, permission: 'write');
        $business->forceFill([
            'media_processing_disclosed_at' => $validated['acknowledged'] ? now() : null,
        ])->save();

        return response()->json([
            'acknowledged' => $business->media_processing_disclosed_at !== null,
            'acknowledged_at' => $business->media_processing_disclosed_at,
        ]);
    }
}
