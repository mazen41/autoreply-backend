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
            $rememberMe = $request->boolean('remember_me');

            $driver = Socialite::driver('google')
                ->stateless()
                ->scopes(['email', 'profile'])
                ->redirectUrl(config('services.google.redirect'));

            // Build state with redirect, package, and billing info
            $stateData = [
                'redirect' => $redirectTo,
                'package' => $packageId,
                'billing' => $billingCycle,
                'remember_me' => $rememberMe,
            ];
            $driver = $driver->with(['state' => base64_encode(json_encode($stateData))]);

            $url = $driver->redirect()->getTargetUrl();

            return response()->json(['url' => $url]);
        } catch (\Exception $e) {
            Log::error('Google redirect error', ['exception' => get_class($e)]);
            return response()->json(['message' => 'Authentication failed'], 401);
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
            $rememberMe = (bool) ($stateData['remember_me'] ?? false);

            // Build redirect params
            $params = [];
            if ($redirectTo) $params[] = 'redirect=' . urlencode($redirectTo);
            if ($packageId) $params[] = 'package=' . urlencode($packageId);
            if ($billingCycle) $params[] = 'billing=' . urlencode($billingCycle);
            if ($rememberMe) $params[] = 'remember_me=1';
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

                return $this->redirectWithLoginCode($user, 'google', false, $redirectParam);
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

                return $this->redirectWithLoginCode($user, 'google', true, $redirectParam);
            }
        } catch (\Exception $e) {
            Log::error('Google callback error', ['exception' => get_class($e)]);
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
            $rememberMe = $request->boolean('remember_me');

            $driver = Socialite::driver('facebook')
                ->stateless()
                ->scopes(['email', 'public_profile'])
                ->redirectUrl(config('services.facebook.redirect'));

            // Build state with redirect, package, and billing info
            $stateData = [
                'redirect' => $redirectTo,
                'package' => $packageId,
                'billing' => $billingCycle,
                'remember_me' => $rememberMe,
            ];
            $driver = $driver->with(['state' => base64_encode(json_encode($stateData))]);

            $url = $driver->redirect()->getTargetUrl();

            return response()->json(['url' => $url]);
        } catch (\Exception $e) {
            Log::error('Facebook redirect error', ['exception' => get_class($e)]);
            return response()->json(['message' => 'Authentication failed'], 401);
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
            $rememberMe = (bool) ($stateData['remember_me'] ?? false);

            // Build redirect params
            $params = [];
            if ($redirectTo) $params[] = 'redirect=' . urlencode($redirectTo);
            if ($packageId) $params[] = 'package=' . urlencode($packageId);
            if ($billingCycle) $params[] = 'billing=' . urlencode($billingCycle);
            if ($rememberMe) $params[] = 'remember_me=1';
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

                return $this->redirectWithLoginCode($user, 'facebook', false, $redirectParam);
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

                return $this->redirectWithLoginCode($user, 'facebook', true, $redirectParam);
            }
        } catch (\Exception $e) {
            Log::error('Facebook callback error', ['exception' => get_class($e)]);
            $frontendUrl = config('services.frontend_url', 'http://localhost:3000');
            return redirect()->away("{$frontendUrl}/login?error=auth_failed");
        }
    }

    public function exchangeLoginCode(Request $request)
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:128'],
            'remember_me' => ['sometimes', 'boolean'],
        ]);
        $exchange = app(\App\Services\OAuthLoginExchange::class)->consume($validated['code']);

        if (!$exchange || !($user = User::find($exchange['user_id'] ?? null))) {
            return response()->json(['message' => 'The sign-in code is invalid or expired.'], 422);
        }

        $expiresAt = now()->addDays((bool) ($validated['remember_me'] ?? false) ? 7 : 3);

        return response()->json([
            'token' => $user->createToken('auth_token', ['*'], $expiresAt)->plainTextToken,
            'expires_at' => $expiresAt->toIso8601String(),
            'provider' => $exchange['provider'],
            'is_new_user' => (bool) $exchange['is_new_user'],
        ]);
    }

    private function redirectWithLoginCode(User $user, string $provider, bool $isNewUser, string $redirectParam)
    {
        $code = app(\App\Services\OAuthLoginExchange::class)->issue($user, $provider, $isNewUser);
        $frontendUrl = config('services.frontend_url', 'http://localhost:3000');
        $query = http_build_query(['code' => $code]);

        return redirect()->away("{$frontendUrl}/auth/callback?{$query}{$redirectParam}");
    }
}
