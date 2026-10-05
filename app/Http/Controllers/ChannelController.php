<?php

namespace App\Http\Controllers;

use App\Models\Channel;
use App\Services\SallaService;
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
                    'user_info'   => $userInfo,
                    'store_info'  => $storeInfo,
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
                'store_id'   => $storeId,
            ]);

            $claimToken = \Illuminate\Support\Facades\Crypt::encryptString(json_encode([
                'c' => $channel->id,
                'e' => now()->addMinutes(15)->timestamp,
            ]));

            return redirect($frontend . '/dashboard/channels?salla_claim=' . urlencode($claimToken));
        } catch (\Exception $e) {
            Log::error('Salla install (no user state) failed', ['message' => $e->getMessage()]);
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
        $user = $request->user();

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

        $channels = Channel::where('user_id', $user->getAuthIdentifier())
            ->latest('connected_at')
            ->get()
            ->map(function ($channel) use ($capabilities) {
            return [
                'id'                   => $channel->id,
                'type'                 => $channel->type,
                'page_id'              => $channel->page_id,
                'page_name'            => $channel->page_name,
                'instagram_account_id' => $channel->instagram_account_id,
                'status'               => in_array($channel->type, ['shopify', 'woocommerce'], true)
                    && ($channel->metadata['sync_status'] ?? '') === 'error'
                        ? 'error'
                        : (in_array($channel->type, ['shopify', 'woocommerce'], true)
                            && in_array(($channel->metadata['sync_status'] ?? ''), ['queued', 'syncing'], true)
                                ? 'syncing'
                                : $channel->status),
                'connected_at'         => $channel->connected_at,
                'ai_enabled'           => $channel->ai_enabled,
                'integration'          => in_array($channel->type, ['shopify', 'woocommerce'], true) ? [
                    'store_url' => $channel->metadata['store_url'] ?? $channel->page_id,
                    'sync_status' => $channel->metadata['sync_status'] ?? 'connected',
                    'sync_counts' => $channel->metadata['sync_counts'] ?? ['products' => 0, 'orders' => 0, 'customers' => 0],
                    'last_synced_at' => $channel->metadata['last_synced_at'] ?? null,
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
        $token = $request->query('token');
        $accessToken = \Laravel\Sanctum\PersonalAccessToken::findToken($token);
        if (!$accessToken) {
            return redirect(env('FRONTEND_URL') . '/dashboard/channels?error=unauthorized');
        }
        $user  = $accessToken->tokenable;
        $state = $user->id . ':' . $request->query('redirect', 'dashboard');

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

        return redirect($url);
    }

    public function callbackFacebook(Request $request)
    {
        \Log::info('=== FACEBOOK CALLBACK START ===');
        \Log::info('All request params', $request->all());

        $code   = $request->get('code');
        $stateParts = explode(':', $request->get('state') ?? '');
        $userId = $stateParts[0] ?? null;
        $error  = $request->get('error');

        \Log::info('Parsed params', [
            'code_exists' => !empty($code),
            'user_id'     => $userId,
            'error'       => $error,
        ]);

        // User denied permissions
        if ($error || !$code) {
            \Log::error('OAuth denied or no code', ['error' => $error]);
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
            'body'   => $tokenResponse->json(),
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

        \Log::info('Got user access token', [
            'token_preview' => substr($userAccessToken, 0, 20) . '...',
        ]);

        // Step 2: Get pages this user manages (classic personal-admin Pages)
        \Log::info('Fetching user pages...');
        $pagesResponse = Http::get('https://graph.facebook.com/v19.0/me/accounts', [
            'access_token' => $userAccessToken,
            'fields'       => 'id,name,access_token,instagram_business_account',
        ]);

        \Log::info('Pages response', [
            'status' => $pagesResponse->status(),
            'body'   => $pagesResponse->json(),
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
                'body'   => $businessesResponse->json(),
            ]);

            $businesses = $businessesResponse->json()['data'] ?? [];

            foreach ($businesses as $business) {
                $ownedPagesResponse = Http::get("https://graph.facebook.com/v19.0/{$business['id']}/owned_pages", [
                    'access_token' => $userAccessToken,
                    'fields'       => 'id,name,access_token,instagram_business_account',
                ]);

                \Log::info('Owned pages response', [
                    'business_id' => $business['id'],
                    'business_name' => $business['name'] ?? null,
                    'status'      => $ownedPagesResponse->status(),
                    'body'        => $ownedPagesResponse->json(),
                ]);

                $ownedPages = $ownedPagesResponse->json()['data'] ?? [];
                $pages = array_merge($pages, $ownedPages);
            }
        }

        \Log::info('Pages found', [
            'count' => count($pages),
            'pages' => array_map(fn($p) => ['id' => $p['id'], 'name' => $p['name']], $pages),
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

            \Log::info('Processing page', ['id' => $pageId, 'name' => $pageName]);

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
            $businessProfile = \App\Models\BusinessProfile::where('user_id', $userId)->first();

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

                // Store page_id on instagram channel for webhook routing
                Channel::where('user_id', $userId)
                    ->where('type', 'instagram')
                    ->where('instagram_account_id', $igAccountId)
                    ->update(['page_id' => $pageId]);
            }

            // Step 5: Subscribe page to webhook — include Instagram fields
            Http::post("https://graph.facebook.com/v19.0/{$pageId}/subscribed_apps", [
                'subscribed_fields' => 'messages,messaging_postbacks,message_echoes,instagram_manage_messages,feed,comments',
                'access_token'      => $longLivedToken,
            ]);

            // Also store page_id on the Instagram channel so webhook routing works
            if ($igAccountId) {
                Channel::where('user_id', $userId)
                    ->where('type', 'instagram')
                    ->where('instagram_account_id', $igAccountId)
                    ->update(['page_id' => $pageId]);
            }
        }

        \Log::info('=== FACEBOOK CALLBACK SUCCESS ===');

        return redirect(env('FRONTEND_URL') . '/dashboard/channels?success=facebook_connected');
    }

    public function disconnect($id)
    {
        $channel = Channel::where('id', $id)
            ->where('user_id', auth()->id())
            ->firstOrFail();

        $channel->delete();

        return response()->json(['message' => 'Channel disconnected']);
    }

    public function update(Request $request, $id)
    {
        $channel = Channel::where('id', $id)
            ->where('user_id', auth()->id())
            ->firstOrFail();

        $validated = $request->validate([
            'ai_enabled' => 'boolean',
        ]);

        $channel->update($validated);

        return response()->json(['message' => 'Channel updated', 'channel' => $channel]);
    }

    /**
     * Connect Salla store
     */
    public function connectSalla(Request $request)
    {
        $token = $request->query('token');
        $accessToken = \Laravel\Sanctum\PersonalAccessToken::findToken($token);
        
        if (!$accessToken) {
            return redirect(env('FRONTEND_URL') . '/dashboard/channels?error=unauthorized');
        }
        
        $user = $accessToken->tokenable;
        $state = $user->id . ':' . $request->query('redirect', 'dashboard');

        $sallaService = new SallaService();
        
        try {
            $authUrl = $sallaService->getAuthorizationUrl($state);
            return redirect($authUrl);
        } catch (\Exception $e) {
            Log::error('Salla connect error', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
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
        Log::info('Request params', $request->except(['code']));

        $code  = $request->get('code');
        $state = $request->get('state');
        $error = $request->get('error');

        if ($error || !$code) {
            Log::error('Salla OAuth denied or no code', ['error' => $error]);
            return redirect(env('FRONTEND_URL') . '/dashboard/channels?error=salla_denied');
        }

        // State carries "userId:redirect" for normal OAuth flow
        // For Salla Easy Mode (app installation), state is random
        $userId   = null;
        $redirect = 'dashboard';
        if ($state) {
            $parts    = explode(':', $state, 2);
            $userId   = $parts[0] ?? null;
            $redirect = $parts[1] ?? 'dashboard';
        }

        // If no valid user ID in state, this is likely Salla Easy Mode installation
        // Redirect to frontend dashboard (authentication middleware will handle redirect to login if needed)
        if (!$userId || !is_numeric($userId)) {
            Log::warning('No valid user ID in Salla OAuth state - Salla-initiated install, exchanging code and issuing claim token');
            return $this->handleSallaInstallWithoutUser($code);
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
                'user_id'     => $userInfo['id'] ?? null,
                'merchant_id' => $merchantId,
                'email'       => $userInfo['email'] ?? null,
            ]);

            // 2b. Fetch store details from the Admin API (name, domain, etc.)
            $storeInfo = $sallaService->getStoreInfo($accessToken);
            $storeId   = $merchantId ?: (string) ($storeInfo['id'] ?? '');
            $storeName = $storeInfo['name'] ?? $userInfo['name'] ?? 'Salla Store';

            if (empty($storeId)) {
                Log::error('Salla callback: could not resolve store/merchant ID', [
                    'user_info'  => $userInfo,
                    'store_info' => $storeInfo,
                ]);
                return redirect(env('FRONTEND_URL') . '/dashboard/channels?error=store_info_failed');
            }

            $businessProfile = \App\Models\BusinessProfile::where('user_id', $userId)->first();

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
                        'user_info'   => $userInfo,
                        'store_info'  => $storeInfo,
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
                        'user_info'   => $userInfo,
                        'store_info'  => $storeInfo,
                    ],
                ]
                );
            }

            Log::info('Salla channel saved', [
                'channel_id' => $channel->id,
                'store_name' => $storeName,
                'store_id'   => $storeId,
                'user_id'    => $userId,
            ]);

            $this->subscribeSallaWebhooks($channel, $accessToken);

            return redirect(env('FRONTEND_URL') . '/dashboard/channels?success=salla_connected');

        } catch (\Exception $e) {
            Log::error('Salla OAuth callback error', [
                'message' => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
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




