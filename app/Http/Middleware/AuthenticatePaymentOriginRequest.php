<?php

namespace App\Http\Middleware;

use App\Models\CentralPaymentNonce;
use App\Models\PaymentClient;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticatePaymentOriginRequest
{
    public function handle(Request $request, Closure $next, string $secretType = 'hmac'): Response
    {
        $publicId = (string) $request->header('X-Central-Payment-Client', '');
        $timestamp = (string) $request->header('X-Central-Payment-Timestamp', '');
        $nonce = (string) $request->header('X-Central-Payment-Nonce', '');
        $signature = strtolower((string) $request->header('X-Central-Payment-Signature', ''));
        if ($publicId === '' || !ctype_digit($timestamp) || abs(now()->timestamp - (int) $timestamp) > 300
            || !preg_match('/^[a-f0-9]{40,128}$/', $nonce)
            || !preg_match('/^[a-f0-9]{64}$/', $signature)) {
            return response()->json(['message' => 'Unauthenticated payment service request.'], 401);
        }

        $client = PaymentClient::query()->where('public_id', $publicId)->where('is_active', true)->first();
        if (!$client) return response()->json(['message' => 'Unauthenticated payment service request.'], 401);

        $secret = $secretType === 'notification' ? $client->notification_secret : $client->hmac_secret;
        $canonical = implode("\n", [
            strtoupper($request->method()),
            $request->getRequestUri(),
            $timestamp,
            $nonce,
            hash('sha256', $request->getContent()),
        ]);
        if (!hash_equals(hash_hmac('sha256', $canonical, $secret), $signature)) {
            return response()->json(['message' => 'Invalid payment service signature.'], 401);
        }

        try {
            CentralPaymentNonce::query()->create([
                'payment_client_id' => $client->id,
                'nonce_hash' => hash('sha256', $nonce),
                'expires_at' => now()->addMinutes(10),
            ]);
        } catch (\Throwable) {
            return response()->json(['message' => 'Request nonce has already been used.'], 409);
        }

        $request->attributes->set('central_payment_client', $client);
        return $next($request);
    }
}
