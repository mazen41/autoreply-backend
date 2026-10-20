<?php

namespace App\Http\Middleware;

use App\Models\BusinessProfile;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class WebChatCors
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!$this->isPublicWidgetRoute($request)) return $next($request);

        $businessId = $request->query('business_id') ?? $request->input('business_id');
        $sessionId = $request->input('session_id') ?? $request->route('sessionId');
        if (!$sessionId && preg_match('#^api/web-chat/sessions/([^/]+)/(?:messages|status)$#', $request->path(), $matches)) {
            $sessionId = rawurldecode($matches[1]);
        }
        if (!$businessId && is_string($sessionId) && $sessionId !== '') {
            $businessId = \App\Models\WebChatSession::query()->where('session_id', $sessionId)->value('business_id');
        }

        $origin = trim((string) $request->header('Origin'));
        $business = $businessId ? BusinessProfile::query()->find($businessId) : null;
        abort_unless($business, 403, 'This website is not authorized to use the chat widget.');
        if (!$business->web_chat_enabled && $request->isMethod('POST') && $request->is('api/web-chat/sessions')) return $next($request);
        abort_unless($origin !== '' && $this->originAllowed($origin, $business), 403, 'This website is not authorized to use the chat widget.');

        if ($request->isMethod('OPTIONS')) {
            return response('', 204, $this->corsHeaders($origin));
        }

        $response = $next($request);
        foreach ($this->corsHeaders($origin) as $name => $value) $response->headers->set($name, $value);
        return $response;
    }

    private function isPublicWidgetRoute(Request $request): bool
    {
        if (!$request->is('api/web-chat/*')) return false;
        $publicWidgetEndpoint = $request->is('api/web-chat/sessions')
            || $request->is('api/web-chat/messages')
            || $request->is('api/web-chat/sessions/*/status')
            || $request->is('api/web-chat/sessions/*/messages');

        // Dashboard configuration/attribution calls live under /web-chat too,
        // but their OPTIONS requests must be handled by Laravel's normal API CORS middleware.
        return $publicWidgetEndpoint && (
            $request->isMethod('OPTIONS')
            || $request->isMethod('POST') && ($request->is('api/web-chat/sessions') || $request->is('api/web-chat/messages') || $request->is('api/web-chat/sessions/*/status'))
            || $request->isMethod('GET') && $request->is('api/web-chat/sessions/*/messages')
        );
    }

    private function originAllowed(string $origin, BusinessProfile $business): bool
    {
        $parts = parse_url($origin);
        if (!is_array($parts) || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true) || empty($parts['host'])) return false;
        $host = strtolower(rtrim((string) $parts['host'], '.'));
        $allowed = collect($business->web_chat_allowed_domains ?? [])->map(fn ($domain) => strtolower(rtrim((string) $domain, '.')));
        $appHosts = collect([config('app.url'), config('app.frontend_url')])
            ->map(fn ($url) => strtolower((string) parse_url((string) $url, PHP_URL_HOST)))
            ->filter();
        return $appHosts->contains($host)
            || $allowed->contains(fn ($domain) => $host === $domain || str_ends_with($host, '.' . $domain));
    }

    private function corsHeaders(string $origin): array
    {
        return [
            'Access-Control-Allow-Origin' => $origin,
            'Access-Control-Allow-Methods' => 'GET, POST, OPTIONS',
            'Access-Control-Allow-Headers' => 'Accept, Content-Type',
            'Access-Control-Max-Age' => '600',
            'Vary' => 'Origin',
        ];
    }
}
