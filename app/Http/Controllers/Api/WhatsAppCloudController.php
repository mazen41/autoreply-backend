<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Channel;
use App\Models\WhatsAppCloudTemplate;
use App\Services\ActiveBusiness;
use App\Services\WhatsAppCloudApiService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class WhatsAppCloudController extends Controller
{
    public function connect(Request $request)
    {
        $business = app(ActiveBusiness::class)->resolve($request, permission: 'write');
        $data = $request->validate([
            'phone_number_id' => 'required|string|max:100',
            'business_account_id' => 'required|string|max:100',
            'access_token' => 'required|string|min:20|max:4096',
            'name' => 'nullable|string|max:255',
        ]);

        $token = $data['access_token'];
        $api = Http::acceptJson()->withToken($token)->timeout(15);
        $version = trim((string) config('services.meta.graph_version', 'v26.0'), '/');
        $base = 'https://graph.facebook.com/' . $version . '/';

        $phoneResponse = $api->get($base . rawurlencode($data['phone_number_id']), [
            'fields' => 'id,display_phone_number,verified_name',
        ]);
        $wabaResponse = $api->get($base . rawurlencode($data['business_account_id']) . '/phone_numbers', [
            'fields' => 'id',
            'limit' => 100,
        ]);
        $phoneBelongsToWaba = collect($wabaResponse->json('data', []))
            ->contains(fn ($phone) => (string) ($phone['id'] ?? '') === $data['phone_number_id']);

        if (!$phoneResponse->successful()
            || (string) $phoneResponse->json('id') !== $data['phone_number_id']
            || !$wabaResponse->successful()
            || !$phoneBelongsToWaba) {
            Log::warning('WhatsApp Cloud connection credential verification failed', [
                'business_id' => $business->id,
                'phone_number_id' => $data['phone_number_id'],
                'http_statuses' => [$phoneResponse->status(), $wabaResponse->status()],
            ]);
            return response()->json([
                'message' => 'Meta could not verify this phone number ID and WhatsApp Business Account ID with the supplied token.',
            ], 422);
        }

        $existing = Channel::where('type', 'whatsapp')
            ->where('page_id', $data['phone_number_id'])
            ->first();

        if ($existing && (int) $existing->business_id !== (int) $business->id) {
            return response()->json([
                'message' => 'This Meta phone number is already connected to another business.',
            ], 409);
        }

        if ($existing && ($existing->metadata['whatsapp_provider'] ?? null) !== 'cloud') {
            return response()->json([
                'message' => 'This phone number is already connected through the legacy WhatsApp session flow. Disconnect it before connecting Cloud API.',
            ], 409);
        }

        $channel = $existing ?? new Channel();
        $channel->fill([
            'user_id' => $request->user()->id,
            'business_id' => $business->id,
            'type' => 'whatsapp',
            'page_id' => $data['phone_number_id'],
            'page_name' => $data['name'] ?: ($phoneResponse->json('verified_name') ?: $phoneResponse->json('display_phone_number')),
            'access_token' => $token,
            'status' => 'connected',
            'connected_at' => now(),
            'metadata' => [
                'whatsapp_provider' => 'cloud',
                'whatsapp_business_account_id' => $data['business_account_id'],
                'display_phone_number' => $phoneResponse->json('display_phone_number'),
                'verified_name' => $phoneResponse->json('verified_name'),
            ],
        ]);
        $channel->save();

        $subscription = app(WhatsAppCloudApiService::class)->subscribeWebhook($channel);
        $metadata = $channel->metadata ?? [];
        $metadata['webhook_subscribed'] = (bool) ($subscription['success'] ?? false);
        $channel->update(['metadata' => $metadata]);

        return response()->json([
            'message' => 'WhatsApp Cloud API connected.',
            'channel' => $channel,
            'webhook_subscribed' => (bool) ($subscription['success'] ?? false),
        ], 201);
    }

    public function templates(Request $request, int $channelId)
    {
        $channel = $this->cloudChannel($request, $channelId);
        $result = app(WhatsAppCloudApiService::class)->syncTemplates($channel);
        if (!$result['success']) {
            return response()->json(['message' => 'Meta template status could not be synced.'], 502);
        }

        return response()->json([
            'templates' => WhatsAppCloudTemplate::where('channel_id', $channel->id)->orderBy('name')->get(),
        ]);
    }

    public function createTemplate(Request $request, int $channelId)
    {
        $channel = $this->cloudChannel($request, $channelId, 'write');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:512', 'regex:/^[a-z0-9_]+$/'],
            'language' => 'required|string|max:20',
            'category' => ['required', Rule::in(['MARKETING', 'UTILITY', 'AUTHENTICATION'])],
            'components' => 'required|array|min:1',
            'components.*.type' => 'required|string|in:HEADER,BODY,FOOTER,BUTTONS',
        ]);

        $result = app(WhatsAppCloudApiService::class)->createTemplate($channel, $data);
        if (!$result['success']) {
            return response()->json(['message' => 'Meta rejected the template submission.'], 422);
        }

        $template = WhatsAppCloudTemplate::updateOrCreate(
            [
                'channel_id' => $channel->id,
                'name' => $data['name'],
                'language' => $data['language'],
            ],
            [
                'business_id' => $channel->business_id,
                'provider_template_id' => $result['data']['id'] ?? null,
                'category' => $data['category'],
                'components' => $data['components'],
                'status' => strtoupper($result['data']['status'] ?? 'PENDING'),
                'rejection_reason' => null,
            ]
        );

        return response()->json(['template' => $template], 201);
    }

    public function acceptUnofficialRisk(Request $request, int $channelId)
    {
        $business = app(ActiveBusiness::class)->resolve($request, permission: 'write');
        abort_unless((int) $business->user_id === (int) $request->user()->id, 403);

        $channel = Channel::where('business_id', $business->id)
            ->where('type', 'whatsapp')
            ->findOrFail($channelId);
        abort_if(($channel->metadata['whatsapp_provider'] ?? null) === 'cloud', 422);

        $metadata = $channel->metadata ?? [];
        $metadata['whatsapp_provider'] = 'evolution';
        $metadata['unofficial_risk_accepted'] = true;
        $metadata['unofficial_risk_accepted_at'] = now()->toISOString();
        $channel->update(['metadata' => $metadata]);

        return response()->json(['message' => 'Legacy WhatsApp messaging risk accepted for this channel.']);
    }

    private function cloudChannel(Request $request, int $channelId, string $permission = 'read'): Channel
    {
        $business = app(ActiveBusiness::class)->resolve($request, permission: $permission);
        return Channel::where('business_id', $business->id)
            ->where('type', 'whatsapp')
            ->whereJsonContains('metadata->whatsapp_provider', 'cloud')
            ->findOrFail($channelId);
    }
}
