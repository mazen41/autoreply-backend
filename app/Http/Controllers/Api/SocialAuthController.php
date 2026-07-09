<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Auth;

class SocialAuthController extends Controller
{
    public function redirectToGoogle()
    {
        try {
            $url = Socialite::driver('google')
                ->stateless()
                ->scopes(['email', 'profile'])
                ->redirectUrl(env('GOOGLE_LOGIN_REDIRECT_URI'))
                ->redirect()
                ->getTargetUrl();

            return response()->json(['url' => $url]);
        } catch (\Exception $e) {
            Log::error('Google redirect error: ' . $e->getMessage());
            return response()->json(['message' => 'Authentication failed', 'error' => $e->getMessage()], 401);
        }
    }

    public function handleGoogleCallback(Request $request)
    {
        try {
            $googleUser = Socialite::driver('google')
                ->stateless()
                ->redirectUrl(env('GOOGLE_LOGIN_REDIRECT_URI'))
                ->user();

            $user = User::where('email', $googleUser->getEmail())->first();

            if ($user) {
                // Update existing user
                if (!$user->google_id) {
                    $user->google_id = $googleUser->getId();
                }
                if (!$user->avatar) {
                    $user->avatar = $googleUser->getAvatar();
                }
                $user->save();

                $token = $user->createToken('auth_token')->plainTextToken;
                $frontendUrl = env('FRONTEND_URL', 'http://localhost:3000');
                return redirect()->away("{$frontendUrl}/auth/callback?token={$token}&provider=google&is_new_user=false");
            } else {
                // Create new user
                $user = User::create([
                    'name' => $googleUser->getName(),
                    'email' => $googleUser->getEmail(),
                    'google_id' => $googleUser->getId(),
                    'avatar' => $googleUser->getAvatar(),
                    'password' => bcrypt(Str::random(32)),
                    'email_verified_at' => now(),
                ]);

                $token = $user->createToken('auth_token')->plainTextToken;
                $frontendUrl = env('FRONTEND_URL', 'http://localhost:3000');
                return redirect()->away("{$frontendUrl}/auth/callback?token={$token}&provider=google&is_new_user=true");
            }
        } catch (\Exception $e) {
            Log::error('Google callback error: ' . $e->getMessage());
            $frontendUrl = env('FRONTEND_URL', 'http://localhost:3000');
            return redirect()->away("{$frontendUrl}/login?error=auth_failed");
        }
    }

    public function redirectToFacebook()
    {
        try {
            $url = Socialite::driver('facebook')
                ->stateless()
                ->scopes(['email', 'public_profile'])
                ->redirectUrl(env('FACEBOOK_LOGIN_REDIRECT_URI'))
                ->redirect()
                ->getTargetUrl();

            return response()->json(['url' => $url]);
        } catch (\Exception $e) {
            Log::error('Facebook redirect error: ' . $e->getMessage());
            return response()->json(['message' => 'Authentication failed', 'error' => $e->getMessage()], 401);
        }
    }

    public function handleFacebookCallback(Request $request)
    {
        try {
            $facebookUser = Socialite::driver('facebook')
                ->stateless()
                ->redirectUrl(env('FACEBOOK_LOGIN_REDIRECT_URI'))
                ->user();

            $user = User::where('email', $facebookUser->getEmail())->first();

            if ($user) {
                // Update existing user
                if (!$user->facebook_id) {
                    $user->facebook_id = $facebookUser->getId();
                }
                if (!$user->avatar) {
                    $user->avatar = $facebookUser->getAvatar();
                }
                $user->save();

                $token = $user->createToken('auth_token')->plainTextToken;
                $frontendUrl = env('FRONTEND_URL', 'http://localhost:3000');
                return redirect()->away("{$frontendUrl}/auth/callback?token={$token}&provider=facebook&is_new_user=false");
            } else {
                // Create new user
                $user = User::create([
                    'name' => $facebookUser->getName(),
                    'email' => $facebookUser->getEmail(),
                    'facebook_id' => $facebookUser->getId(),
                    'avatar' => $facebookUser->getAvatar(),
                    'password' => bcrypt(Str::random(32)),
                    'email_verified_at' => now(),
                ]);

                $token = $user->createToken('auth_token')->plainTextToken;
                $frontendUrl = env('FRONTEND_URL', 'http://localhost:3000');
                return redirect()->away("{$frontendUrl}/auth/callback?token={$token}&provider=facebook&is_new_user=true");
            }
        } catch (\Exception $e) {
            Log::error('Facebook callback error: ' . $e->getMessage());
            $frontendUrl = env('FRONTEND_URL', 'http://localhost:3000');
            return redirect()->away("{$frontendUrl}/login?error=auth_failed");
        }
    }
}
