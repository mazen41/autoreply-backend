<?php

namespace App\Http\Controllers;

use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TikTokController extends Controller
{
    public function connect(Request $request)
    {
        $token = $request->query('token');
        $accessToken = \Laravel\Sanctum\PersonalAccessToken::findToken($token);
        if (!$accessToken) {
            return redirect(env('FRONTEND_URL') . '/dashboard/channels?error=unauthorized');
        }
        $user = $accessToken->tokenable;
        $state = $user->id . ':' . $request->query('redirect', 'dashboard');

        $clientId = env('TIKTOK_CLIENT_KEY');
        $redirectUri = env('TIKTOK_REDIRECT_URI');

        $scopes = implode(',', [
            'user.info.basic',
            'user.video.list',
            'video.list',
            'video.comments',
        ]);

        $url = 'https://open.tiktokapis.com/v2/oauth/authorize/?' . http_build_query([
            'client_key' => $clientId,
            'redirect_uri' => $redirectUri,
            'scope' => $scopes,
            'response_type' => 'code',
            'state' => $state,
        ]);

        return redirect($url);
    }

    public function callback(Request $request)
    {
        Log::info('=== TIKTOK CALLBACK START ===');
        Log::info('All request params', $request->all());

        $code = $request->get('code');
        $stateParts = explode(':', $request->get('state') ?? '');
        $userId = $stateParts[0] ?? null;
        $error = $request->get('error');

        if ($error || !$code) {
            Log::error('TikTok OAuth denied or no code', ['error' => $error]);
            return redirect(env('FRONTEND_URL') . '/dashboard/channels?error=tiktok_denied');
        }

        if (!$userId) {
            Log::error('No user ID in TikTok state');
            return redirect(env('FRONTEND_URL') . '/dashboard/channels?error=session_expired');
        }

        $clientId = env('TIKTOK_CLIENT_KEY');
        $clientSecret = env('TIKTOK_CLIENT_SECRET');
        $redirectUri = env('TIKTOK_REDIRECT_URI');

        try {
            // Exchange code for access token
            $tokenResponse = Http::asForm()->post('https://open.tiktokapis.com/v2/oauth/token/', [
                'client_key' => $clientId,
                'client_secret' => $clientSecret,
                'code' => $code,
                'grant_type' => 'authorization_code',
                'redirect_uri' => $redirectUri,
            ]);

            if (!$tokenResponse->successful()) {
                Log::error('TikTok token exchange failed', [
                    'status' => $tokenResponse->status(),
                    'body' => $tokenResponse->json(),
                ]);
                return redirect(env('FRONTEND_URL') . '/dashboard/channels?error=token_failed');
            }

            $tokenData = $tokenResponse->json();
            $accessToken = $tokenData['access_token'] ?? null;

            if (!$accessToken) {
                Log::error('No access token in TikTok response');
                return redirect(env('FRONTEND_URL') . '/dashboard/channels?error=token_failed');
            }

            // Get user info
            $userResponse = Http::withToken($accessToken)
                ->get('https://open.tiktokapis.com/v2/user/info/?fields=open_id,username,display_name');

            if (!$userResponse->successful()) {
                Log::error('Failed to get TikTok user info', [
                    'status' => $userResponse->status(),
                    'body' => $userResponse->json(),
                ]);
                return redirect(env('FRONTEND_URL') . '/dashboard/channels?error=user_info_failed');
            }

            $userInfo = $userResponse->json();
            $tiktokUser = $userInfo['data']['user'] ?? [];
            $username = $tiktokUser['username'] ?? 'TikTok User';
            $displayName = $tiktokUser['display_name'] ?? $username;
            $openId = $tiktokUser['open_id'] ?? '';

            // Save channel
            $businessProfile = \App\Models\BusinessProfile::where('user_id', $userId)->first();

            $channel = Channel::updateOrCreate(
                [
                    'user_id' => $userId,
                    'type' => 'tiktok',
                    'page_id' => $openId,
                ],
                [
                    'page_name' => $displayName,
                    'access_token' => $accessToken,
                    'status' => 'connected',
                    'connected_at' => now(),
                    'business_id' => $businessProfile ? $businessProfile->id : null,
                    'metadata' => [
                        'username' => $username,
                        'open_id' => $openId,
                    ],
                ]
            );

            Log::info('TikTok channel connected', [
                'user_id' => $userId,
                'username' => $username,
                'channel_id' => $channel->id,
            ]);

            return redirect(env('FRONTEND_URL') . '/dashboard/channels?success=tiktok_connected');

        } catch (\Exception $e) {
            Log::error('TikTok connection error', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return redirect(env('FRONTEND_URL') . '/dashboard/channels?error=connection_failed');
        }
    }

    public function webhook(Request $request)
    {
        try {
            $update = $request->all();
            Log::info('TikTok webhook received', $update);

            // TikTok's actual public Webhooks API (developers.tiktok.com/doc/webhooks-events)
            // only defines FOUR events, every one delivered in this envelope:
            //   { client_key, event, create_time, user_openid, content }
            // `user_openid` is always present at the top level (except for
            // portability.download.ready) and is the verified, documented identifier
            // for the connected account — it is exactly what `openId`/`page_id` is set
            // to in connect()/callback() above, now that the $tikTokUser/$tiktokUser
            // typo is fixed.
            if (isset($update['user_openid']) && isset($update['event'])) {
                $userOpenId = $update['user_openid'];
                $eventName = $update['event'];
                $content = json_decode($update['content'] ?? '{}', true) ?? [];

                $channel = Channel::where('type', 'tiktok')
                    ->where('page_id', $userOpenId)
                    ->first();

                if (!$channel) {
                    Log::warning('TikTok webhook: no channel found for user_openid', ['user_openid' => $userOpenId]);
                    return response('OK', 200);
                }

                if ($eventName === 'authorization.removed') {
                    // Documented `content.reason`: 0 unknown, 1 user disconnect,
                    // 2 account deleted, 3 age change, 4 banned, 5 developer revoke.
                    $channel->update([
                        'status' => 'disconnected',
                        'metadata' => array_merge($channel->metadata ?? [], [
                            'disconnected_reason' => $content['reason'] ?? null,
                            'disconnected_at' => now()->toISOString(),
                        ]),
                    ]);
                    Log::info('TikTok channel deauthorized', [
                        'channel_id' => $channel->id,
                        'reason' => $content['reason'] ?? null,
                    ]);
                } else {
                    // video.upload.failed / video.publish.completed / portability.download.ready
                    // are informational Video Kit events — no conversation/message is
                    // implied by them. Logged for now; wire up handling here if/when the
                    // product needs to react to them.
                    Log::info('TikTok webhook event (no handler needed yet)', [
                        'channel_id' => $channel->id,
                        'event' => $eventName,
                        'content' => $content,
                    ]);
                }

                return response('OK', 200);
            }

            // -----------------------------------------------------------------
            // UNVERIFIED LEGACY PATH — DO NOT EXTEND.
            //
            // The block below handles a `comment` payload shape that does not match
            // TikTok's documented public Webhooks API in any way (see the block above
            // for the real, documented envelope). TikTok's public API has no
            // comment-created webhook event, so this code path has no known way to
            // actually be triggered by TikTok in production. Per the audit
            // instructions ("do not guess the account identifier... mark clearly
            // instead of inventing behavior"), this is left in place unmodified
            // rather than rewritten, since fixing its account-resolution logic would
            // require guessing a payload shape TikTok does not document. If this
            // needs to work, it requires product/vendor clarification: TikTok does
            // not offer comment-reply automation via its public API — this would
            // need a different provider or TikTok's (invite-only) Comment Kit.
            // -----------------------------------------------------------------
            if (isset($update['comment'])) {
                $comment = $update['comment'];
                $text = $comment['text'] ?? '';
                $userId = $comment['user']['user_id'] ?? '';
                $videoId = $comment['video_id'] ?? '';

                if (empty($text)) {
                    return response('OK', 200);
                }

                // Find channel by user_id (stored in metadata)
                $channel = Channel::where('type', 'tiktok')
                    ->where('status', 'connected')
                    ->whereJsonContains('metadata->username', $userId)
                    ->first();

                if (!$channel) {
                    Log::warning('TikTok webhook: no channel found for user', ['user_id' => $userId]);
                    return response('OK', 200);
                }

                // Create conversation
                $conversation = Conversation::firstOrCreate(
                    ['channel_id' => $channel->id, 'sender_id' => $userId],
                    [
                        'business_id' => $channel->business_id,
                        'sender_name' => $comment['user']['nickname'] ?? 'TikTok User',
                        'status' => 'open',
                        'last_message_at' => now(),
                    ]
                );

                $conversation->update(['last_message_at' => now()]);

                // Resolve/link the business-scoped Customer (platform-identity matching)
                try {
                    app(\App\Services\CustomerService::class)->attachToConversation($conversation);
                } catch (\Throwable $e) {
                    Log::warning('TikTok webhook: customer resolution failed', [
                        'conversation_id' => $conversation->id,
                        'error'           => $e->getMessage(),
                    ]);
                }

                // Create message
                $messageModel = Message::create([
                    'conversation_id' => $conversation->id,
                    'content' => $text,
                    'direction' => 'inbound',
                    'is_ai' => false,
                    'status' => 'received',
                ]);

                // Broadcast and dispatch AI reply
                if ($channel->user_id) {
                    broadcast(new \App\Events\MessageReceived($messageModel, $conversation, $channel->user_id));
                }

                \App\Jobs\ProcessAutoReply::dispatch($messageModel->id);
            }

            return response('OK', 200);

        } catch (\Exception $e) {
            Log::error('TikTok webhook error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response('OK', 200);
        }
    }
}