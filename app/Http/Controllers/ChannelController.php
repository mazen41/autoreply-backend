<?php

namespace App\Http\Controllers;

use App\Models\Channel;
use App\Services\SallaService;
use App\Services\OAuthStateService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ChannelController extends Controller
{
    /**
     * Salla-initiated install (portal "Install" button / Easy Mode). Salla supplies its own
     * random `state`, so we can't tell which Naz user this is. Authorization codes are
     * short-lived and single-use, so exchange it NOW, store the channel unclaimed
     * (user_id = null), then redirect with a short-lived encrypted claim token that the
     * logged-in frontend redeems via POST /api/channels/salla/claim.
     */
    protected function handleSallaInstallWithoutUser(string $code)
    {
        $frontend = env('FRONTEND_URL');

        try {
            $sallaService = new SallaService();
            $tokenData    = $sallaService->exchangeCodeForToken($code);
            $accessToken  = $tokenData['access_token'];
            $refreshToken = $tokenData['refresh_token'] ?? null;
            $expiresIn    = $tokenData['expires_in'] ?? 3600;

            $userInfo   = $sallaService->getUserInfo($accessToken);
            $merchantId = (string) ($userInfo['merchant']['id'] ?? $userInfo['id'] ?? '');
            $storeInfo  = $sallaService->getStoreInfo($accessToken);
            $storeId    = $merchantId ?: (string) ($storeInfo['id'] ?? '');
            $storeName  = $storeInfo['name'] ?? $userInfo['name'] ?? 'Salla Store';

            if (empty($storeId)) {
                Log::error('Salla install: could not resolve store/merchant ID');
                return redirect($frontend . '/dashboard/channels?error=store_info_failed');
            }

            $fields = [
                'page_name'        => $storeName,
                'access_token'     => $accessToken,
                'refresh_token'    => $refreshToken,
                'token_expires_at' => now()->addSeconds($expiresIn),
                'status'           => 'connected',
                'connected_at'     => now(),
                'metadata'         => [
                    'scopes'      => $tokenData['scope'] ?? null,
                    'merchant_id' => $merchantId,
                ],
            ];

            // Re-install of a store that already belongs to a Naz user: refresh its tokens.
            $claimed = Channel::where('type', 'salla')->where('page_id', $storeId)->whereNotNull('user_id')->first();
            if ($claimed) {
                $claimed->update($fields);
                Log::info('Salla install: refreshed tokens on existing channel', ['channel_id' => $claimed->id]);
                return redirect($frontend . '/dashboard/channels?success=salla_connected');
            }

            $channel = Channel::where('type', 'salla')->where('page_id', $storeId)->whereNull('user_id')->first();
            if ($channel) {
                $channel->update($fields);
            } else {
                $channel = Channel::create($fields + ['type' => 'salla', 'page_id' => $storeId, 'user_id' => null]);
            }

            Log::info('Salla install: unclaimed channel stored, awaiting claim', [
                'channel_id' => $channel->id,
            ]);

            $claimToken = \Illuminate\Support\Facades\Crypt::encryptString(json_encode([
                'c' => $channel->id,
                'e' => now()->addMinutes(15)->timestamp,
            ]));

            return redirect($frontend . '/dashboard/channels?salla_claim=' . urlencode($claimToken));
        } catch (\Exception $e) {
            Log::error('Salla install (no user state) failed', ['exception_type' => get_class($e)]);
            return redirect($frontend . '/dashboard/channels?error=salla_oauth_failed');
        }
    }

    /**
     * Attach an unclaimed Salla channel (created by handleSallaInstallWithoutUser) to the
     * authenticated Naz user. Requires the short-lived claim token from the redirect.
     */
    public function claimSalla(Request $request)
    {
        $request->validate(['token' => 'required|string']);

        try {
            $payload = json_decode(\Illuminate\Support\Facades\Crypt::decryptString($request->input('token')), true);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'error' => 'Invalid claim token'], 422);
        }

        if (!is_array($payload) || ($payload['e'] ?? 0) < now()->timestamp) {
            return response()->json(['success' => false, 'error' => 'Claim token expired. Please reconnect Salla.'], 422);
        }

        $channel = Channel::where('id', $payload['c'] ?? 0)
            ->where('type', 'salla')
            ->whereNull('user_id')
            ->first();

        if (!$channel) {
            return response()->json(['success' => false, 'error' => 'Salla store not found or already claimed'], 404);
        }

        $userId   = $request->user()->getAuthIdentifier();
        $business = \App\Models\BusinessProfile::where('user_id', $userId)->first();

        $existing = Channel::where('user_id', $userId)
            ->where('type', 'salla')
            ->where('page_id', $channel->page_id)
            ->first();

        if ($existing) {
            $existing->update([
                'page_name'        => $channel->page_name,
                'access_token'     => $channel->access_token,
                'refresh_token'    => $channel->refresh_token,
                'token_expires_at' => $channel->token_expires_at,
                'status'           => 'connected',
                'connected_at'     => now(),
                'metadata'         => $channel->metadata,
            ]);
            $channel->delete();
            $channel = $existing;
        } else {
            $channel->update([
                'user_id'      => $userId,
                'business_id'  => $business?->id,
                'status'       => 'connected',
                'connected_at' => now(),
            ]);
        }

        Log::info('Salla channel claimed', ['channel_id' => $channel->id, 'user_id' => $userId]);

        return response()->json(['success' => true, 'channel_id' => $channel->id]);
    }

    public function index(Request $request)
    {
        $business = app(\App\Services\ActiveBusiness::class)->resolve($request, permission: 'read');

        // Phase 4 audit: explicit per-channel integration capabilities, so the
        // UI can show what each connection can actually do instead of letting
        // the product silently fail. TikTok is connected-but-not-automatable:
        //   • TikTok's public Webhooks API defines NO comment-created event, so
        //     there is no real inbound message source (the legacy `comment`
        //     payload handler in TikTokController is unreachable in practice).
        //   • TikTok's public API has no comment-reply/DM endpoint —
        //     ProcessAutoReply::sendTikTokReply() is an honest placeholder that
        //     always fails (never fakes a send).
        // Real automation would require TikTok's invite-only Comment Kit.
        $capabilities = [
            'tiktok' => [
                'inbound_webhook' => false,
                'inbound_ai_reply' => false,
                'outbound_send' => false,
                'limitations' => 'TikTok automation is not available: the public API provides no comment-created webhook and no comment-reply/DM endpoint. Inbound AI replies and automated sequences cannot operate on this channel.',
            ],
        ];

        $channels = Channel::where('business_id', $business->id)
            ->with('bots:id,status,business_profile_id')
            ->latest('connected_at')
            ->get()
            ->map(function ($channel) use ($capabilities, $business) {
            $businessBots = $channel->bots->where('business_profile_id', $business->id)->values();
            $activeBots = $businessBots->where('status', 'active')->values();
            $primaryBot = $activeBots->first(fn ($bot) => (bool) ($bot->pivot->is_primary ?? false));
            $resolvedBot = $activeBots->count() === 1
                ? $activeBots->first()
                : ($activeBots->count() > 1 ? $primaryBot : null);
            $noAgentFailClosedEnabled = \App\Models\FeatureFlag::isEnabled('phase3_no_agent_fail_closed', (int) $business->id);
            $legacyFallbackAllowed = !$noAgentFailClosedEnabled;
            $routingAvailable = (bool) $resolvedBot || $legacyFallbackAllowed;
            $effectiveAiEnabled = (bool) $channel->ai_enabled
                && $channel->status === 'connected'
                && $routingAvailable;
            $aiPauseReason = !$channel->ai_enabled
                ? 'channel_ai_paused'
                : ($channel->status !== 'connected'
                    ? 'channel_not_connected'
                    : (!$routingAvailable
                        ? ($activeBots->count() > 1 ? 'multiple_active_agents_without_primary' : 'no_active_agent')
                        : null));

            return [
                'id'                   => $channel->id,
                'type'                 => $channel->type,
                'page_id'              => $channel->page_id,
                'page_name'            => $channel->page_name,
                'instagram_account_id' => $channel->instagram_account_id,
                'status'               => in_array($channel->type, ['shopify', 'woocommerce'], true)
                    && (($channel->metadata['sync_status'] ?? '') === 'error'
                        || in_array(($channel->metadata['webhook_status'] ?? ''), ['error', 'partial'], true))
                        ? 'error'
                        : (in_array($channel->type, ['shopify', 'woocommerce'], true)
                            && in_array(($channel->metadata['sync_status'] ?? ''), ['queued', 'syncing'], true)
                                ? 'syncing'
                                : $channel->status),
                    'status_message'       => in_array($channel->type, ['shopify', 'woocommerce'], true)
                        ? ($channel->metadata['sync_error']
                        ?? (in_array(($channel->metadata['webhook_status'] ?? ''), ['error', 'partial'], true)
                            ? (($channel->metadata['webhook_failure_message'] ?? null)
                                ? 'Store webhooks are incomplete: ' . $channel->metadata['webhook_failure_message']
                                : 'Store webhooks could not all be registered; automatic updates may be delayed.')
                            : null))
                        : null,
                'connected_at'         => $channel->connected_at,
                'ai_enabled'           => $channel->ai_enabled,
                'effective_ai_enabled' => $effectiveAiEnabled,
                'ai_pause_reason'      => $aiPauseReason,
                // Used by the bot mapping wizard to show a primary-responder
                // control only when multiple active bots share this account.
                'bots'                 => $activeBots->map(fn ($bot) => [
                    'id' => $bot->id,
                    'name' => $bot->name,
                    'status' => $bot->status,
                    'pivot' => ['is_primary' => (bool) ($bot->pivot->is_primary ?? false)],
                ])->values(),
                'default_ecommerce_connection_id' => $channel->default_ecommerce_connection_id,
                'integration'          => in_array($channel->type, ['shopify', 'woocommerce'], true) ? [
                    'store_url' => $channel->metadata['store_url'] ?? $channel->page_id,
                    'sync_status' => $channel->metadata['sync_status'] ?? 'connected',
                    'sync_counts' => $channel->metadata['sync_counts'] ?? ['products' => 0, 'orders' => 0, 'customers' => 0],
                    'last_synced_at' => $channel->metadata['last_synced_at'] ?? null,
                    'sync_error' => $channel->metadata['sync_error'] ?? null,
                    'sync_warnings' => $channel->metadata['sync_warnings'] ?? [],
                    'webhook_status' => $channel->metadata['webhook_status'] ?? null,
                    'webhooks_registered' => $channel->metadata['webhooks_registered'] ?? null,
                    'webhook_failure_message' => $channel->metadata['webhook_failure_message'] ?? null,
                ] : null,
                'capabilities'         => $capabilities[$channel->type] ?? [
                    'inbound_webhook'  => true,
                    'inbound_ai_reply' => true,
                    'outbound_send'    => true,
                    'limitations'      => null,
                ],
            ];
        });

        return response()->json([
            'data' => $channels,
        ]);
    }

    public function connectFacebook(Request $request)
    {
        $user = $request->user();
        abort_unless($user, 401);
        $business = app(\App\Services\ActiveBusiness::class)->resolve($request, permission: 'write');
        $state = app(OAuthStateService::class)->issue('facebook', (int) $user->id, [
            'redirect' => 'dashboard', 'business_id' => $business->id,
        ]);

        $appId       = env('META_APP_ID');
        $redirectUri = env('APP_URL') . '/api/channels/callback/facebook';

        $scopes = implode(',', [
            'pages_messaging',
            'pages_show_list',
            'instagram_basic',
            'instagram_manage_messages',
            'pages_read_engagement',
            'pages_manage_metadata',
            'business_management',
            'public_profile',
        ]);

        $url = 'https://www.facebook.com/v19.0/dialog/oauth?' . http_build_query([
            'client_id'     => $appId,
            'redirect_uri'  => $redirectUri,
            'scope'         => $scopes,
            'response_type' => 'code',
            'state'         => $state,
        ]);

        return response()->json(['url' => $url]);
    }

    public function callbackFacebook(Request $request)
    {
        \Log::info('=== FACEBOOK CALLBACK START ===');
        \Log::info('OAuth callback received', [
            'has_code' => $request->filled('code'),
            'has_state' => $request->filled('state'),
            'has_provider_error' => $request->filled('error'),
        ]);

        $stateData = app(OAuthStateService::class)->consume('facebook', $request->query('state'));
        $userId = $stateData['user_id'] ?? null;
        if (!$userId || !\App\Models\User::whereKey($userId)->exists()) {
            return redirect(env('FRONTEND_URL') . '/dashboard/channels?error=facebook_invalid_state');
        }
        try {
            $businessProfile = app(\App\Services\BusinessAccessService::class)->authorize(
                \App\Models\User::findOrFail($userId),
                (int) ($stateData['context']['business_id'] ?? 0),
                'write',
                hideUnauthorized: false,
            );
        } catch (\Throwable) {
            return redirect(env('FRONTEND_URL') . '/dashboard/channels?error=facebook_invalid_state');
        }

        $code   = $request->get('code');
        $error  = $request->get('error');

        \Log::info('Facebook OAuth callback parsed', [
            'code_exists' => !empty($code),
            'has_user_id' => !empty($userId),
            'has_provider_error' => !empty($error),
        ]);

        // User denied permissions
        if ($error || !$code) {
            \Log::error('OAuth denied or no code', ['has_provider_error' => !empty($error)]);
            return redirect(env('FRONTEND_URL') . '/dashboard/channels?error=facebook_denied');
        }

        // No user ID in state
        if (!$userId) {
            \Log::error('No user ID in state');
            return redirect(env('FRONTEND_URL') . '/dashboard/channels?error=session_expired');
        }

        $appId       = env('META_APP_ID');
        $appSecret   = env('META_APP_SECRET');
        $redirectUri = env('APP_URL') . '/api/channels/callback/facebook';

        \Log::info('App credentials check', [
            'app_id_exists'     => !empty($appId),
            'app_secret_exists' => !empty($appSecret),
            'redirect_uri'      => $redirectUri,
        ]);

        // Step 1: Exchange code for user access token
        \Log::info('Exchanging code for token...');
        $tokenResponse = Http::get('https://graph.facebook.com/v19.0/oauth/access_token', [
            'client_id'     => $appId,
            'client_secret' => $appSecret,
            'redirect_uri'  => $redirectUri,
            'code'          => $code,
        ]);

        \Log::info('Token exchange response', [
            'status' => $tokenResponse->status(),
            'has_access_token' => !empty($tokenResponse->json()['access_token'] ?? null),
        ]);

        if (!$tokenResponse->successful()) {
            \Log::error('Token exchange FAILED');
            return redirect(env('FRONTEND_URL') . '/dashboard/channels?error=token_failed');
        }

        $userAccessToken = $tokenResponse->json()['access_token'] ?? null;

        if (!$userAccessToken) {
            \Log::error('No access token in response');
            return redirect(env('FRONTEND_URL') . '/dashboard/channels?error=token_failed');
        }

        \Log::info('Facebook user access token received');

        // Step 2: Get pages this user manages (classic personal-admin Pages)
        \Log::info('Fetching user pages...');
        $pagesResponse = Http::get('https://graph.facebook.com/v19.0/me/accounts', [
            'access_token' => $userAccessToken,
            'fields'       => 'id,name,access_token,instagram_business_account',
        ]);

        \Log::info('Pages response', [
            'status' => $pagesResponse->status(),
            'page_count' => count($pagesResponse->json()['data'] ?? []),
        ]);

        $pages = $pagesResponse->json()['data'] ?? [];

        // Fallback: Pages that only exist as Business Portfolio assets don't show up
        // under /me/accounts. Look them up via the user's Businesses instead.
        if (empty($pages)) {
            \Log::info('No pages via /me/accounts, trying Business Portfolio fallback...');

            $businessesResponse = Http::get('https://graph.facebook.com/v19.0/me/businesses', [
                'access_token' => $userAccessToken,
                'fields'       => 'id,name',
            ]);

            \Log::info('Businesses response', [
                'status' => $businessesResponse->status(),
                'business_count' => count($businessesResponse->json()['data'] ?? []),
            ]);

            $businesses = $businessesResponse->json()['data'] ?? [];

            foreach ($businesses as $business) {
                $ownedPagesResponse = Http::get("https://graph.facebook.com/v19.0/{$business['id']}/owned_pages", [
                    'access_token' => $userAccessToken,
                    'fields'       => 'id,name,access_token,instagram_business_account',
                ]);

                \Log::info('Owned pages response', [
                    'status'      => $ownedPagesResponse->status(),
                    'page_count'  => count($ownedPagesResponse->json()['data'] ?? []),
                ]);

                $ownedPages = $ownedPagesResponse->json()['data'] ?? [];
                $pages = array_merge($pages, $ownedPages);
            }
        }

        \Log::info('Pages found', [
            'count' => count($pages),
        ]);

        if (empty($pages)) {
            \Log::error('NO PAGES FOUND');
            return redirect(env('FRONTEND_URL') . '/dashboard/channels?error=no_pages');
        }

        // Process all pages
        foreach ($pages as $page) {
            $pageId          = $page['id'];
            $pageName        = $page['name'];
            $pageAccessToken = $page['access_token'];

            \Log::info('Processing Facebook page connection');

            // Exchange short-lived page token for long-lived token
            $longLivedResponse = Http::get('https://graph.facebook.com/v19.0/oauth/access_token', [
                'grant_type'        => 'fb_exchange_token',
                'client_id'         => $appId,
                'client_secret'     => $appSecret,
                'fb_exchange_token' => $pageAccessToken,
            ]);

            $longLivedToken = $longLivedResponse->successful() 
                ? ($longLivedResponse->json()['access_token'] ?? $pageAccessToken)
                : $pageAccessToken;

            // Step 3: Save Facebook channel with encrypted token
            $channel = Channel::updateOrCreate(
                [
                    'user_id' => $userId,
                    'type'    => 'facebook',
                    'page_id' => $pageId,
                ],
                [
                    'page_name'    => $pageName,
                    'access_token' => $longLivedToken,  // mutator encrypts this automatically
                    'status'       => 'connected',
                    'connected_at' => now(),
                    'business_id'  => $businessProfile ? $businessProfile->id : null,
                ]
            );

            \Log::info('Facebook channel saved', ['channel_id' => $channel->id]);

            // Step 4: Get and save Instagram account
            $igAccountId = $page['instagram_business_account']['id'] ?? null;

            if (!$igAccountId) {
                $igResponse = Http::get("https://graph.facebook.com/v19.0/{$pageId}", [
                    'fields'       => 'instagram_business_account',
                    'access_token' => $longLivedToken,
                ]);
                $igAccountId = $igResponse->json()['instagram_business_account']['id'] ?? null;
            }

            if ($igAccountId) {
                Channel::updateOrCreate(
                    [
                        'user_id'             => $userId,
                        'type'                => 'instagram',
                        'instagram_account_id' => $igAccountId,
                    ],
                    [
                        'page_name'    => $pageName . ' (Instagram)',
                        'access_token' => $longLivedToken,
                        'status'       => 'connected',
                        'connected_at' => now(),
                        'business_id'  => $businessProfile ? $businessProfile->id : null,
                    ]
                );
                \Log::info('Instagram channel saved', ['account_id' => $igAccountId]);

                  // The Instagram row is identified by instagram_account_id only.
                  // It must never carry the Facebook page ID: Messenger events would then route
                  // to the Instagram channel and its store.
            }

            // Subscribe to supported Facebook and Instagram webhook fields.
            // `instagram_manage_messages` is a permission, not a webhook field.
            $subscribedFields = 'messages,messaging_postbacks,message_echoes,message_reads,feed,comments';
            if ($igAccountId) {
                $subscribedFields .= ',messaging_seen';
            }
            Http::post("https://graph.facebook.com/v19.0/{$pageId}/subscribed_apps", [
                'subscribed_fields' => $subscribedFields,
                'access_token'      => $longLivedToken,
            ]);

        }

        \Log::info('=== FACEBOOK CALLBACK SUCCESS ===');

        return redirect(env('FRONTEND_URL') . '/dashboard/channels?success=facebook_connected');
    }

    public function disconnect(Request $request, $id)
    {
        $business = app(\App\Services\ActiveBusiness::class)->resolve($request, permission: 'write');
        $channel = Channel::where('id', $id)
            ->where('business_id', $business->id)
            ->firstOrFail();

        $channel->delete();

        return response()->json(['message' => 'Channel disconnected']);
    }

    public function update(Request $request, $id)
    {
        $business = app(\App\Services\ActiveBusiness::class)->resolve($request, permission: 'write');
        $channel = Channel::where('id', $id)
            ->where('business_id', $business->id)
            ->firstOrFail();

        $validated = $request->validate([
            'ai_enabled' => 'boolean',
            'default_ecommerce_connection_id' => 'nullable|integer',
            'auto_order_confirmation_enabled' => 'nullable|boolean',
        ]);

        if ($channel->type === 'tiktok' && ($validated['ai_enabled'] ?? false)) {
            return response()->json([
                'message' => 'TikTok messaging is unsupported; automated replies cannot be enabled for this channel.',
            ], 422);
        }

        if (
            array_key_exists('ai_enabled', $validated)
            && $request->boolean('ai_enabled')
            && \App\Models\FeatureFlag::isEnabled('phase3_setup_verification_required', (int) $business->id)
        ) {
            $readiness = app(\App\Services\AiSetupVerificationService::class)->checklist($business);
            if (!$readiness['ready']) {
                return response()->json([
                    'message' => 'Complete the AI setup checklist before enabling replies.',
                    'ready' => false,
                    'checks' => $readiness['checks'],
                    'missing' => $readiness['missing'],
                ], 422);
            }
        }

        if (array_key_exists('default_ecommerce_connection_id', $validated) && $validated['default_ecommerce_connection_id'] !== null) {
            $connection = Channel::query()
                ->whereKey($validated['default_ecommerce_connection_id'])
                ->where('business_id', $channel->business_id)
                ->where('status', 'connected')
                ->whereIn('type', ['salla', 'shopify', 'woocommerce'])
                ->first();

            if (!$connection) {
                return response()->json(['message' => 'The selected store is not connected to this business.'], 422);
            }
        }

        $channel->update($validated);

        return response()->json(['message' => 'Channel updated', 'channel' => $channel]);
    }

    /**
     * Connect Salla store
     */
    public function connectSalla(Request $request)
    {
        $user = $request->user();
        abort_unless($user, 401);
        $business = app(\App\Services\ActiveBusiness::class)->resolve($request, permission: 'write');
        $state = app(OAuthStateService::class)->issue('salla', (int) $user->id, [
            'redirect' => 'dashboard', 'business_id' => $business->id,
        ]);

        $sallaService = new SallaService();
        
        try {
            $authUrl = $sallaService->getAuthorizationUrl($state);
            return response()->json(['url' => $authUrl]);
        } catch (\Exception $e) {
            Log::error('Salla connect error', [
                'user_id' => $user->id,
                'exception_type' => get_class($e),
            ]);
            return redirect(env('FRONTEND_URL') . '/dashboard/channels?error=salla_config_error');
        }
    }

    /**
     * Handle Salla OAuth callback
     */
    public function callbackSalla(Request $request)
    {
        Log::info('=== SALLA CALLBACK START ===');
        Log::info('Salla OAuth callback received', [
            'has_code' => $request->filled('code'),
            'has_state' => $request->filled('state'),
            'has_provider_error' => $request->filled('error'),
        ]);

        $code  = $request->get('code');
        $state = (string) $request->get('state', '');
        $stateData = $state !== '' ? app(OAuthStateService::class)->consume('salla', $state) : null;
        $error = $request->get('error');

        // Salla's Easy Mode supplies its own opaque state. Preserve that
        // unclaimed-install flow, but never reinterpret an expired or forged
        // NazBiz state (or the old user-id state format) as an Easy Mode install.
        if (!$stateData && ($state === '' || str_starts_with($state, 'naz_') || preg_match('/^\d+:/', $state))) {
            if ($state !== '') {
                return redirect(env('FRONTEND_URL') . '/dashboard/channels?error=salla_invalid_state');
            }
        }

        if ($error || !$code) {
            Log::error('Salla OAuth denied or no code', ['has_provider_error' => !empty($error)]);
            return redirect(env('FRONTEND_URL') . '/dashboard/channels?error=salla_denied');
        }

        $userId = $stateData['user_id'] ?? null;

        // If no valid user ID in state, this is likely Salla Easy Mode installation
        // Redirect to frontend dashboard (authentication middleware will handle redirect to login if needed)
        if (!$userId || !is_numeric($userId)) {
            Log::warning('No valid user ID in Salla OAuth state - Salla-initiated install, exchanging code and issuing claim token');
            return $this->handleSallaInstallWithoutUser($code);
        }

        try {
            $businessProfile = app(\App\Services\BusinessAccessService::class)->authorize(
                \App\Models\User::findOrFail($userId),
                (int) ($stateData['context']['business_id'] ?? 0),
                'write',
                hideUnauthorized: false,
            );
        } catch (\Throwable) {
            return redirect(env('FRONTEND_URL') . '/dashboard/channels?error=salla_invalid_state');
        }

        try {
            $sallaService = new SallaService();

            // 1. Exchange code for tokens
            $tokenData    = $sallaService->exchangeCodeForToken($code);
            $accessToken  = $tokenData['access_token'];
            $refreshToken = $tokenData['refresh_token'] ?? null;
            $expiresIn    = $tokenData['expires_in'] ?? 3600;

            // 2a. Fetch user/merchant info from the OAuth user info endpoint
            //     This gives us the merchant ID (used as our page_id / store identifier)
            $userInfo   = $sallaService->getUserInfo($accessToken);
            $merchantId = (string) ($userInfo['merchant']['id'] ?? $userInfo['id'] ?? '');

            Log::info('Salla user info received', [
                'has_user_id' => !empty($userInfo['id']),
                'has_merchant_id' => $merchantId !== '',
                'has_email' => !empty($userInfo['email']),
            ]);

            // 2b. Fetch store details from the Admin API (name, domain, etc.)
            $storeInfo = $sallaService->getStoreInfo($accessToken);
            $storeId   = $merchantId ?: (string) ($storeInfo['id'] ?? '');
            $storeName = $storeInfo['name'] ?? $userInfo['name'] ?? 'Salla Store';

            if (empty($storeId)) {
                Log::error('Salla callback: could not resolve store/merchant ID', [
                    'user_info_keys' => array_keys($userInfo),
                    'store_info_keys' => array_keys($storeInfo),
                ]);
                return redirect(env('FRONTEND_URL') . '/dashboard/channels?error=store_info_failed');
            }

            // 3. Check for an orphaned placeholder created by app.installed webhook
            $existing = Channel::where('type', 'salla')
                ->where('page_id', $storeId)
                ->whereNull('user_id')
                ->orWhere(fn ($q) => $q->where('type', 'salla')
                    ->where('page_id', $storeId)
                    ->where('user_id', 0))
                ->first();

            if ($existing) {
                // Claim the placeholder for this user
                $existing->update([
                    'user_id'          => $userId,
                    'page_name'        => $storeName,
                    'access_token'     => $accessToken,
                    'refresh_token'    => $refreshToken,
                    'token_expires_at' => now()->addSeconds($expiresIn),
                    'status'           => 'connected',
                    'connected_at'     => now(),
                    'business_id'      => $businessProfile?->id,
                    'metadata'         => array_merge($existing->metadata ?? [], [
                        'scopes'      => $tokenData['scope'] ?? null,
                        'merchant_id' => $merchantId,
                    ]),
                ]);
                $channel = $existing->fresh();
            } else {
                // Normal upsert keyed on (user_id, type, page_id)
                $channel = Channel::updateOrCreate(
                    [
                        'user_id' => $userId,
                        'type'    => 'salla',
                        'page_id' => $storeId,
                    ],
                    [
                        'page_name'        => $storeName,
                        'access_token'     => $accessToken,
                        'refresh_token'    => $refreshToken,
                        'token_expires_at' => now()->addSeconds($expiresIn),
                        'status'           => 'connected',
                        'connected_at'     => now(),
                        'business_id'      => $businessProfile?->id,
                        'metadata'         => [
                        'scopes'      => $tokenData['scope'] ?? null,
                        'merchant_id' => $merchantId,
                    ],
                ]
                );
            }

            Log::info('Salla channel saved', [
                'channel_id' => $channel->id,
            ]);

            $this->subscribeSallaWebhooks($channel, $accessToken);

            return redirect(env('FRONTEND_URL') . '/dashboard/channels?success=salla_connected');

        } catch (\Exception $e) {
            Log::error('Salla OAuth callback error', [
                'exception_type' => get_class($e),
            ]);
            return redirect(env('FRONTEND_URL') . '/dashboard/channels?error=salla_oauth_failed');
        }
    }

    /**
     * Subscribe to Salla webhooks
     */
    protected function subscribeSallaWebhooks(Channel $channel, string $accessToken)
    {
        $webhookUrl = env('APP_URL') . '/api/salla/webhook';
        
        // Note: Salla webhooks are configured in the Salla app dashboard
        // We don't need to subscribe programmatically if already configured there
        // This method is kept for future programmatic webhook management
        
        Log::info('Salla webhooks should be configured in app dashboard', [
            'channel_id' => $channel->id,
            'webhook_url' => $webhookUrl,
        ]);
        
        // If you want to implement programmatic webhook subscription,
        // you would need to use the correct Salla API endpoint
        // which might be different from the one we assumed
    }
}
