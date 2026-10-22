<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Services\ActiveBusiness;
use App\Services\BusinessAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ApiKeyController extends Controller
{
    public function index(Request $request, ActiveBusiness $activeBusiness, BusinessAccessService $businessAccess)
    {
        $businessId = $this->businessId($request, $activeBusiness, $businessAccess);
        $keys = ApiKey::where('user_id', $request->user()->id)
            ->where('business_id', $businessId)
            ->latest()
            ->get()
            ->map(fn (ApiKey $key) => $this->publicFields($key));

        return response()->json(['data' => $keys]);
    }

    public function store(Request $request, ActiveBusiness $activeBusiness, BusinessAccessService $businessAccess)
    {
        $businessId = $this->businessId($request, $activeBusiness, $businessAccess);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'scopes' => ['required', 'array', 'min:1', 'max:20'],
            'scopes.*' => ['required', 'string', 'distinct', 'in:conversations:read,messages:read,messages:write'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ]);

        $secret = 'naz_live_' . Str::random(48);
        $digest = hash('sha256', $secret);
        $key = ApiKey::create([
            'user_id' => $request->user()->id,
            'business_id' => $businessId,
            'key' => $digest,
            'secret_hash' => $digest,
            'key_last_four' => substr($secret, -4),
            // Newly issued credentials are intentionally not recoverable from
            // storage. The one-time response is the only copy of the secret.
            'secret_ciphertext' => null,
            'name' => trim($validated['name']),
            'permissions' => $validated['scopes'],
            'expires_at' => $validated['expires_at'] ?? null,
            'is_active' => true,
        ]);

        // The secret is returned only on creation. Listing endpoints reveal a
        // short masked suffix so an existing credential cannot be recovered.
        return response()->json($this->publicFields($key, oneTimeSecret: $secret), 201);
    }

    public function destroy(Request $request, int $id, ActiveBusiness $activeBusiness, BusinessAccessService $businessAccess)
    {
        $businessId = $this->businessId($request, $activeBusiness, $businessAccess);
        $key = ApiKey::where('user_id', $request->user()->id)->where('business_id', $businessId)->find($id);
        if (!$key) {
            return response()->json(['error' => 'API key not found'], 404);
        }

        $key->delete();
        return response()->json(['success' => true]);
    }

    public function toggle(Request $request, int $id, ActiveBusiness $activeBusiness, BusinessAccessService $businessAccess)
    {
        $businessId = $this->businessId($request, $activeBusiness, $businessAccess);
        $key = ApiKey::where('user_id', $request->user()->id)->where('business_id', $businessId)->find($id);
        if (!$key) {
            return response()->json(['error' => 'API key not found'], 404);
        }

        $key->update(['is_active' => !$key->is_active]);
        return response()->json(['success' => true, 'is_active' => $key->is_active]);
    }

    private function publicFields(ApiKey $key, ?string $oneTimeSecret = null): array
    {
        return [
            'id' => $key->id,
            'business_id' => $key->business_id,
            'name' => $key->name,
            'key' => $oneTimeSecret ?? '••••••••' . ($key->key_last_four ?: substr($key->key, -4)),
            'scopes' => $key->permissions ?? [],
            'is_active' => (bool) $key->is_active,
            'last_used_at' => $key->last_used_at,
            'expires_at' => $key->expires_at,
            'created_at' => $key->created_at,
        ];
    }

    /**
     * Honor the dashboard's selected workspace. Legacy clients without a
     * workspace header remain limited to the user's primary business; users
     * without one can only create/list keys that have no data access.
     */
    private function businessId(Request $request, ActiveBusiness $activeBusiness, BusinessAccessService $businessAccess): ?int
    {
        if ($request->header('X-Business-Id') || $request->filled('business_id')) {
            return (int) $activeBusiness->resolve($request, permission: 'write')->id;
        }

        $primaryBusinessId = $request->user()->business_id;
        if (!$primaryBusinessId) {
            return null;
        }

        return (int) $businessAccess->authorize($request->user(), $primaryBusinessId, 'write')->id;
    }
}
