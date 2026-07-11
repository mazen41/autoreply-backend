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
    public function redirectToGoogle(Request $request)
    {
        try {
            $redirectTo = $request->query('redirect');
            $packageId = $request->query('package');
            $billingCycle = $request->query('billing');

            $driver = Socialite::driver('google')
                ->stateless()
                ->scopes(['email', 'profile'])
                ->redirectUrl(config('services.google.redirect'));

            // Build state with redirect, package, and billing info
            $stateData = [
                'redirect' => $redirectTo,
                'package' => $packageId,
                'billing' => $billingCycle,
            ];
            $driver = $driver->with(['state' => base64_encode(json_encode($stateData))]);

            $url = $driver->redirect()->getTargetUrl();

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
                ->redirectUrl(config('services.google.redirect'))
                ->user();

            $user = User::where('email', $googleUser->getEmail())->first();

            $state = $request->query('state');
            $stateData = $state ? json_decode(base64_decode($state), true) : [];
            $redirectTo = $stateData['redirect'] ?? null;
            $packageId = $stateData['package'] ?? null;
            $billingCycle = $stateData['billing'] ?? null;

            // Build redirect params
            $params = [];
            if ($redirectTo) $params[] = 'redirect=' . urlencode($redirectTo);
            if ($packageId) $params[] = 'package=' . urlencode($packageId);
            if ($billingCycle) $params[] = 'billing=' . urlencode($billingCycle);
            $redirectParam = !empty($params) ? '&' . implode('&', $params) : '';

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
                $frontendUrl = config('services.frontend_url', 'http://localhost:3000');
                return redirect()->away("{$frontendUrl}/auth/callback?token={$token}&provider=google&is_new_user=false{$redirectParam}");
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
                $frontendUrl = config('services.frontend_url', 'http://localhost:3000');
                return redirect()->away("{$frontendUrl}/auth/callback?token={$token}&provider=google&is_new_user=true{$redirectParam}");
            }
        } catch (\Exception $e) {
            Log::error('Google callback error: ' . $e->getMessage());
            $frontendUrl = config('services.frontend_url', 'http://localhost:3000');
            return redirect()->away("{$frontendUrl}/login?error=auth_failed");
        }
    }

    public function redirectToFacebook(Request $request)
    {
        try {
            $redirectTo = $request->query('redirect');
            $packageId = $request->query('package');
            $billingCycle = $request->query('billing');

            $driver = Socialite::driver('facebook')
                ->stateless()
                ->scopes(['email', 'public_profile'])
                ->redirectUrl(config('services.facebook.redirect'));

            // Build state with redirect, package, and billing info
            $stateData = [
                'redirect' => $redirectTo,
                'package' => $packageId,
                'billing' => $billingCycle,
            ];
            $driver = $driver->with(['state' => base64_encode(json_encode($stateData))]);

            $url = $driver->redirect()->getTargetUrl();

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
                ->redirectUrl(config('services.facebook.redirect'))
                ->user();

            $user = User::where('email', $facebookUser->getEmail())->first();

            $state = $request->query('state');
            $stateData = $state ? json_decode(base64_decode($state), true) : [];
            $redirectTo = $stateData['redirect'] ?? null;
            $packageId = $stateData['package'] ?? null;
            $billingCycle = $stateData['billing'] ?? null;

            // Build redirect params
            $params = [];
            if ($redirectTo) $params[] = 'redirect=' . urlencode($redirectTo);
            if ($packageId) $params[] = 'package=' . urlencode($packageId);
            if ($billingCycle) $params[] = 'billing=' . urlencode($billingCycle);
            $redirectParam = !empty($params) ? '&' . implode('&', $params) : '';

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
                $frontendUrl = config('services.frontend_url', 'http://localhost:3000');
                return redirect()->away("{$frontendUrl}/auth/callback?token={$token}&provider=facebook&is_new_user=false{$redirectParam}");
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
                $frontendUrl = config('services.frontend_url', 'http://localhost:3000');
                return redirect()->away("{$frontendUrl}/auth/callback?token={$token}&provider=facebook&is_new_user=true{$redirectParam}");
            }
        } catch (\Exception $e) {
            Log::error('Facebook callback error: ' . $e->getMessage());
            $frontendUrl = config('services.frontend_url', 'http://localhost:3000');
            return redirect()->away("{$frontendUrl}/login?error=auth_failed");
        }
    }
}
