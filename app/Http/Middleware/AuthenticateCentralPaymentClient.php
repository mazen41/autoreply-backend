<?php

namespace App\Http\Middleware;

use App\Models\CentralPaymentNonce;
use App\Models\PaymentClient;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateCentralPaymentClient
{
    public function handle(Request $request, Closure $next): Response
    {
        $publicId = (string) $request->header('X-Payment-Client', '');
        $apiKey = preg_replace('/^Bearer\s+/i', '', (string) $request->header('Authorization', ''));
        $timestamp = (string) $request->header('X-Payment-Timestamp', '');
        $nonce = (string) $request->header('X-Payment-Nonce', '');
        $signature = strtolower((string) $request->header('X-Payment-Signature', ''));

        if ($publicId === '' || $apiKey === '' || !ctype_digit($timestamp)
            || abs(now()->timestamp - (int) $timestamp) > 300
            || !preg_match('/^[A-Za-z0-9_-]{16,128}$/', $nonce)
            || !preg_match('/^[a-f0-9]{64}$/', $signature)) {
            return response()->json(['message' => 'Unauthenticated payment client.'], 401);
        }

        $client = PaymentClient::query()
            ->where('public_id', $publicId)
            ->where('is_active', true)
            ->first();

        if (!$client || !hash_equals($client->api_key_hash, hash('sha256', $apiKey))) {
            return response()->json(['message' => 'Unauthenticated payment client.'], 401);
        }

        $path = $request->getRequestUri();
        $bodyHash = hash('sha256', $request->getContent());
        $canonical = implode("\n", [
            strtoupper($request->method()), $path, $timestamp, $nonce, $bodyHash,
        ]);
        $expected = hash_hmac('sha256', $canonical, $client->hmac_secret);

        if (!hash_equals($expected, $signature)) {
            Log::warning('Central payment client signature rejected', [
                'client_id' => $client->public_id,
                'method' => strtoupper($request->method()),
                'path' => $path,
                'timestamp' => $timestamp,
                'body_sha256' => $bodyHash,
            ]);
            return response()->json(['message' => 'Invalid request signature.'], 401);
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
