<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Analytics\VisitorAnalyticsQueryService;
use App\Models\VisitorProperty;
use App\Models\VisitorEvent;
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
            'allowed_domains.*' => 'string|max:190',
        ]);

        $domain = $this->normalizeDomain($validated['domain']);
        if (!$domain) {
            return response()->json(['message' => 'A valid domain or URL is required.'], 422);
        }
        $allowedDomains = collect($validated['allowed_domains'] ?? [$domain])
            ->map(fn ($item) => $this->normalizeDomain($item))
            ->filter()->unique()->values()->all();
        if (!$allowedDomains) {
            return response()->json(['message' => 'At least one valid allowed domain is required.'], 422);
        }

        $uid = 'naz_site_' . Str::random(12);

        $property = VisitorProperty::create([
            'property_uid' => $uid,
            'name' => $validated['name'],
            'domain' => $domain,
            'type' => $validated['type'] ?? 'customer_store',
            'platform' => $validated['platform'] ?? 'custom',
            'allowed_domains' => $allowedDomains,
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
        $validated = $request->validate([
            'property_id' => 'sometimes|string|max:190',
            'preset' => 'sometimes|string|in:today,yesterday,7d,30d,90d',
        ]);
        $propertyUid = $validated['property_id'] ?? null;
        $preset = $validated['preset'] ?? '30d';

        $data = $this->queryService->getDashboardMetrics($propertyUid, $preset);

        return response()->json($data);
    }

    /**
     * Real-time live active visitors radar feed
     * GET /api/admin/visitor-analytics/live
     */
    public function getLive(Request $request)
    {
        $validated = $request->validate(['property_id' => 'sometimes|string|max:190']);
        $propertyUid = $validated['property_id'] ?? null;

        $data = $this->queryService->getLiveFeed($propertyUid);

        return response()->json($data);
    }

    /**
     * Test and verify tracking tag beacon
     * POST /api/admin/visitor-analytics/verify
     */
    public function verify(Request $request)
    {
        $validated = $request->validate(['property_id' => 'required|string|max:190']);
        $propertyUid = $validated['property_id'];
        $property = VisitorProperty::where('property_uid', $propertyUid)->where('is_active', true)->first();

        if ($property) {
            $latestEvent = VisitorEvent::where('property_id', $property->id)
                ->where('created_at', '>=', now()->subMinutes(10))
                ->latest('created_at')->first();
            if (!$latestEvent) {
                return response()->json([
                    'verified' => false,
                    'error' => 'No tracking event received in the last 10 minutes. Install the tag and visit the site first.',
                ], 422);
            }

            $property->update(['verified_at' => now()]);
            return response()->json([
                'verified' => true,
                'property_id' => $propertyUid,
                'verified_at' => $property->verified_at->toIso8601String(),
                'last_event_at' => $latestEvent->created_at?->toIso8601String(),
            ]);
        }

        return response()->json(['verified' => false, 'error' => 'Property not found'], 404);
    }

    private function normalizeDomain(string $value): ?string
    {
        $value = trim($value);
        $host = parse_url(str_contains($value, '://') ? $value : '//' . $value, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return null;
        }

        $host = strtolower(rtrim($host, '.'));
        return $host === 'localhost' || filter_var($host, FILTER_VALIDATE_IP) || filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)
            ? $host
            : null;
    }
}
