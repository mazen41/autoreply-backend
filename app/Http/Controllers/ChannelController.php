<?php

namespace App\Http\Controllers;

use App\Models\Channel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ChannelController extends Controller
{
    public function index(Request $request)
    {
        $channels = Channel::where('user_id', auth()->id())->get()->map(function ($channel) {
            return [
                'id'                   => $channel->id,
                'type'                 => $channel->type,
                'page_id'              => $channel->page_id,
                'page_name'            => $channel->page_name,
                'instagram_account_id' => $channel->instagram_account_id,
                'status'               => $channel->status,
                'connected_at'         => $channel->connected_at,
                'ai_enabled'           => $channel->ai_enabled,
            ];
        });

        return response()->json($channels);
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
            'pages_manage_metadata',
            'pages_show_list',
            'pages_read_engagement',
            'instagram_basic',
            'instagram_manage_messages',
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
        $tokenResponse = Http::withOptions(['verify' => false])
            ->get('https://graph.facebook.com/v19.0/oauth/access_token', [
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

        // Step 2: Get pages this user manages
        \Log::info('Fetching user pages...');
        $pagesResponse = Http::withOptions(['verify' => false])
            ->get('https://graph.facebook.com/v19.0/me/accounts', [
                'access_token' => $userAccessToken,
                'fields'       => 'id,name,access_token,instagram_business_account',
            ]);

        \Log::info('Pages response', [
            'status' => $pagesResponse->status(),
            'body'   => $pagesResponse->json(),
        ]);

        $pages = $pagesResponse->json()['data'] ?? [];

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
            $longLivedResponse = Http::withOptions(['verify' => false])
                ->get('https://graph.facebook.com/v19.0/oauth/access_token', [
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
                $igResponse = Http::withOptions(['verify' => false])
                    ->get("https://graph.facebook.com/v19.0/{$pageId}", [
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
            }

            // Step 5: Subscribe page to webhook
            Http::withOptions(['verify' => false])
                ->post("https://graph.facebook.com/v19.0/{$pageId}/subscribed_apps", [
                    'subscribed_fields' => 'messages,messaging_postbacks,message_echoes',
                    'access_token'      => $longLivedToken,
                ]);
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
}





