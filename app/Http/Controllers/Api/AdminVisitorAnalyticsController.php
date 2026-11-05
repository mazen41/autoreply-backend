<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Analytics\VisitorAnalyticsQueryService;
use App\Models\VisitorProperty;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AdminVisitorAnalyticsController extends Controller
{
    public function __construct(private VisitorAnalyticsQueryService $queryService)
    {
    }

    /**
     * List all tracked properties with live visitor counts
     * GET /api/admin/visitor-analytics/properties
     */
    public function getProperties(Request $request)
    {
        $properties = $this->queryService->getProperties();

        return response()->json([
            'properties' => $properties,
        ]);
    }

    /**
     * Create a new tracking property for a website or store
     * POST /api/admin/visitor-analytics/properties
     */
    public function createProperty(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:190',
            'domain' => 'required|string|max:190',
            'type' => 'nullable|string|in:internal,customer_store,custom_site',
            'platform' => 'nullable|string|in:nextjs,shopify,salla,woocommerce,custom',
            'allowed_domains' => 'nullable|array',
        ]);

        $uid = 'naz_site_' . Str::random(12);

        $property = VisitorProperty::create([
            'property_uid' => $uid,
            'name' => $validated['name'],
            'domain' => $validated['domain'],
            'type' => $validated['type'] ?? 'customer_store',
            'platform' => $validated['platform'] ?? 'custom',
            'allowed_domains' => $validated['allowed_domains'] ?? [$validated['domain']],
            'settings' => ['anonymize_ip' => true, 'respect_dnt' => true],
            'is_active' => true,
            'verified_at' => null,
        ]);

        return response()->json([
            'success' => true,
            'property' => $property,
        ], 201);
    }

    /**
     * Get aggregated analytics dashboard data
     * GET /api/admin/visitor-analytics/dashboard
     */
    public function getDashboard(Request $request)
    {
        $propertyUid = $request->query('property_id');
        $preset = $request->query('preset', '30d');

        $data = $this->queryService->getDashboardMetrics($propertyUid, $preset);

        return response()->json($data);
    }

    /**
     * Real-time live active visitors radar feed
     * GET /api/admin/visitor-analytics/live
     */
    public function getLive(Request $request)
    {
        $propertyUid = $request->query('property_id');

        $data = $this->queryService->getLiveFeed($propertyUid);

        return response()->json($data);
    }

    /**
     * Test and verify tracking tag beacon
     * POST /api/admin/visitor-analytics/verify
     */
    public function verify(Request $request)
    {
        $propertyUid = $request->input('property_id');
        $property = VisitorProperty::where('property_uid', $propertyUid)->first();

        if ($property) {
            $property->update(['verified_at' => now()]);
            return response()->json([
                'verified' => true,
                'property_id' => $propertyUid,
                'verified_at' => $property->verified_at->toIso8601String(),
            ]);
        }

        return response()->json(['verified' => false, 'error' => 'Property not found'], 404);
    }
}
