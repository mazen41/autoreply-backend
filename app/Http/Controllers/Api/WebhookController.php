<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\DeliverWebhook;
use App\Models\Webhook;
use App\Services\BusinessAccessService;
use App\Services\WebhookService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class WebhookController extends Controller
{
    public function index(Request $request, $businessId)
    {
        app(BusinessAccessService::class)->authorize($request->user(), $businessId, 'read');

        $webhooks = Webhook::where('business_id', $businessId)
            ->orderByDesc('created_at')
            ->get()
            ->makeHidden(['secret']);

        return response()->json($webhooks);
    }

    public function store(Request $request, $businessId)
    {
        app(BusinessAccessService::class)->authorize($request->user(), $businessId, 'write');

        $validated = $request->validate([
            'url' => 'required|url',
            'events' => 'required|array',
            'events.*' => 'required|string|max:100',
            'secret' => 'nullable|string|max:255',
        ]);
        $this->ensureHttps($validated['url']);
        $generatedSecret = blank($validated['secret'] ?? null) ? Str::random(64) : null;

        $webhook = Webhook::create([
            'business_id' => $businessId,
            'url' => $validated['url'],
            'events' => $validated['events'],
            'secret' => $generatedSecret ?? $validated['secret'],
            'is_active' => true,
        ]);

        // Reveal an auto-generated signing key only once so the owner can
        // configure their receiver. List/update/test responses never expose it.
        $webhookResponse = $webhook->makeHidden(['secret'])->toArray();
        if ($generatedSecret !== null) {
            $webhookResponse['secret'] = $generatedSecret;
        }

        return response()->json(['success' => true, 'webhook' => $webhookResponse], 201);
    }

    public function update(Request $request, $businessId, $webhookId)
    {
        app(BusinessAccessService::class)->authorize($request->user(), $businessId, 'write');

        $validated = $request->validate([
            'url' => 'sometimes|required|url',
            'events' => 'sometimes|required|array',
            'events.*' => 'required|string|max:100',
            'secret' => 'sometimes|nullable|string|max:255',
            'is_active' => 'sometimes|boolean',
        ]);
        if (isset($validated['url'])) {
            $this->ensureHttps($validated['url']);
        }

        $webhook = Webhook::where('business_id', $businessId)->findOrFail($webhookId);
        if (array_key_exists('secret', $validated) && blank($validated['secret'])) {
            // Blank form fields mean "leave the existing secret alone". To
            // rotate it, the caller must submit a replacement secret.
            unset($validated['secret']);
        }
        $webhook->update($validated);

        return response()->json(['success' => true, 'webhook' => $webhook->fresh()->makeHidden(['secret'])]);
    }

    public function destroy(Request $request, $businessId, $webhookId)
    {
        app(BusinessAccessService::class)->authorize($request->user(), $businessId, 'write');

        $webhook = Webhook::where('business_id', $businessId)->findOrFail($webhookId);
        $webhook->delete();

        return response()->json(['success' => true]);
    }

    public function test(Request $request, $businessId, $webhookId)
    {
        app(BusinessAccessService::class)->authorize($request->user(), $businessId, 'write');
        $webhook = Webhook::where('business_id', $businessId)->findOrFail($webhookId);
        $this->ensureHttps($webhook->url);

        DeliverWebhook::dispatch($webhook->id, 'test', [
            'test' => true,
            'message' => 'Webhook test from Naz platform',
            'timestamp' => now()->toISOString(),
        ], true);

        return response()->json(['success' => true, 'message' => 'Test webhook queued']);
    }

    public function rotateSecret(Request $request, $businessId, $webhookId)
    {
        app(BusinessAccessService::class)->authorize($request->user(), $businessId, 'write');
        $webhook = Webhook::where('business_id', $businessId)->findOrFail($webhookId);
        $secret = Str::random(64);
        $webhook->secret = $secret;
        $webhook->save();

        // A replacement key is returned only by this explicit rotation call.
        return response()->json(['success' => true, 'secret' => $secret]);
    }

    private function ensureHttps(string $url): void
    {
        try {
            app(WebhookService::class)->validateDestination($url);
        } catch (\Throwable $e) {
            throw ValidationException::withMessages(['url' => [$e->getMessage()]]);
        }
    }
}
