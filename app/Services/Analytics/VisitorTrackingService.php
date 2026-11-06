<?php

namespace App\Services\Analytics;

use App\Models\VisitorProperty;
use App\Models\VisitorIdentity;
use App\Models\VisitorSession;
use App\Models\VisitorEvent;
use App\Models\VisitorConversion;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class VisitorTrackingService
{
    /**
     * Ingest an analytics payload from tracking SDK or webhook
     */
    public function ingest(array $data, Request $request): array
    {
        $propertyUid = $data['property_id'] ?? $data['property_uid'] ?? null;
        if (!$propertyUid) {
            return ['success' => false, 'error' => 'Missing property_id'];
        }

        $property = VisitorProperty::where('property_uid', $propertyUid)->first();
        if (!$property) {
            return ['success' => false, 'error' => 'Unknown property_id'];
        }

        if (!$property->is_active) {
            return ['success' => false, 'error' => 'Property is inactive'];
        }

        // Browser Origin is not an authentication mechanism, but it prevents
        // accidental cross-domain collection and catches a misconfigured tag.
        $origin = $request->header('Origin') ?: $request->header('Referer');
        if ($origin && !empty($property->allowed_domains)) {
            $parsedHost = strtolower((string) parse_url($origin, PHP_URL_HOST));
            $allowed = array_map(static function ($domain) {
                $domain = trim((string) $domain);
                $host = parse_url(str_contains($domain, '://') ? $domain : '//' . $domain, PHP_URL_HOST);
                return is_string($host) ? strtolower(rtrim($host, '.')) : '';
            }, $property->allowed_domains);
            if (!$parsedHost || !in_array($parsedHost, $allowed, true)) {
                Log::warning('Analytics domain mismatch rejected', [
                    'property' => $property->property_uid,
                    'host' => $parsedHost,
                    'allowed_domains' => $allowed,
                ]);
                return ['success' => false, 'error' => 'Origin is not allowed for this property'];
            }
        }

        $settings = $property->settings ?? [];
        if (($settings['respect_dnt'] ?? true) && ($request->header('DNT') === '1' || $request->header('Sec-GPC') === '1')) {
            return ['success' => true, 'ignored' => true, 'reason' => 'privacy_signal'];
        }

        $anonymousId = $data['anonymous_id'] ?? $data['visitor_id'] ?? Str::uuid()->toString();
        $sessionId = $data['session_id'] ?? Str::uuid()->toString();
        // Public browser payloads are untrusted. Only authenticated server-side
        // linkIdentity() may associate anonymous activity with a customer user.
        $userId = null;

        // Anonymize IP
        $rawIp = $request->ip() ?: '127.0.0.1';
        $ipHash = hash('sha256', $this->anonymizeIp($rawIp) . date('Y-m-d'));

        // Parse User-Agent
        $ua = $request->userAgent() ?: ($data['user_agent'] ?? '');
        $clientInfo = $this->parseUserAgent($ua);

        // Geolocation (Coarse country / city)
        $geo = $this->resolveGeo($request, []);

        // 1. Resolve or create Identity
        $identity = VisitorIdentity::firstOrCreate(
            ['property_id' => $property->id, 'anonymous_id' => $anonymousId],
            [
                'user_id' => $userId,
                'first_seen_at' => now(),
                'last_seen_at' => now(),
                'traits' => null,
            ]
        );

        if ($userId && !$identity->user_id) {
            $identity->update(['user_id' => $userId]);
        }
        $identity->update(['last_seen_at' => now()]);

        // 2. Resolve or create Session
        $session = VisitorSession::where('property_id', $property->id)
            ->where('session_id', $sessionId)
            ->first();

        // Store canonical page/referrer URLs without query strings or fragments:
        // those often contain reset tokens, email addresses, and other secrets.
        $allowedHosts = array_map(static function ($domain) {
            $host = parse_url(str_contains((string) $domain, '://') ? (string) $domain : '//' . (string) $domain, PHP_URL_HOST);
            return is_string($host) ? strtolower(rtrim($host, '.')) : '';
        }, $property->allowed_domains ?: [$property->domain]);
        $rawUrl = (string) ($data['url'] ?? '');
        $urlParts = $rawUrl !== '' ? parse_url($rawUrl) : false;
        $pageHost = is_array($urlParts) && !empty($urlParts['host'])
            ? strtolower(rtrim($urlParts['host'], '.'))
            : (string) (parse_url(str_contains((string) $property->domain, '://') ? (string) $property->domain : '//' . (string) $property->domain, PHP_URL_HOST) ?: $property->domain);
        if ($allowedHosts && !in_array($pageHost, $allowedHosts, true)) {
            return ['success' => false, 'error' => 'Page URL domain is not allowed for this property'];
        }
        $rawPath = (string) ($data['path'] ?? (is_array($urlParts) ? ($urlParts['path'] ?? '/') : '/'));
        $path = parse_url($rawPath, PHP_URL_PATH) ?: '/';
        if (!str_starts_with($path, '/') || str_starts_with($path, '//')) {
            $path = '/';
        }
        $scheme = is_array($urlParts) && in_array($urlParts['scheme'] ?? '', ['http', 'https'], true)
            ? $urlParts['scheme']
            : 'https';
        $port = is_array($urlParts) && isset($urlParts['port']) ? ':' . (int) $urlParts['port'] : '';
        $url = $scheme . '://' . $pageHost . $port . $path;

        $rawReferrer = $data['referrer'] ?? null;
        $referrerParts = is_string($rawReferrer) && $rawReferrer !== '' ? parse_url($rawReferrer) : false;
        $referrerDomain = is_array($referrerParts) && !empty($referrerParts['host'])
            ? strtolower(rtrim($referrerParts['host'], '.'))
            : null;
        $referrerScheme = is_array($referrerParts) && in_array($referrerParts['scheme'] ?? '', ['http', 'https'], true)
            ? $referrerParts['scheme']
            : 'https';
        $referrer = $referrerDomain
            ? ($referrerScheme . '://' . $referrerDomain)
            : null;

        if (!$session) {
            $session = VisitorSession::create([
                'session_id' => $sessionId,
                'property_id' => $property->id,
                'visitor_identity_id' => $identity->id,
                'user_id' => $userId,
                'ip_hash' => $ipHash,
                'country' => $geo['country'],
                'country_code' => $geo['country_code'],
                'city' => $geo['city'],
                'device_type' => $clientInfo['device'],
                'browser' => $clientInfo['browser'],
                'os' => $clientInfo['os'],
                'referrer' => $referrer,
                'referrer_domain' => $referrerDomain,
                'entry_url' => $url,
                'exit_url' => $url,
                'utm_source' => $data['utm_source'] ?? null,
                'utm_medium' => $data['utm_medium'] ?? null,
                'utm_campaign' => $data['utm_campaign'] ?? null,
                'utm_term' => $data['utm_term'] ?? null,
                'utm_content' => $data['utm_content'] ?? null,
                'pageviews_count' => ($data['type'] ?? 'pageview') === 'pageview' ? 1 : 0,
                'events_count' => 1,
                'duration_seconds' => 0,
                'started_at' => now(),
                'last_activity_at' => now(),
            ]);
        } else {
            $isPageview = ($data['type'] ?? 'pageview') === 'pageview';
            $duration = max(0, now()->diffInSeconds($session->started_at));

            $session->increment('events_count');
            if ($isPageview) {
                $session->increment('pageviews_count');
            }

            $session->update([
                'exit_url' => $url,
                'last_activity_at' => now(),
                'duration_seconds' => $duration,
                'user_id' => $userId ?: $session->user_id,
            ]);
        }

        // 3. Record Event
        $eventType = $data['type'] ?? 'pageview';
        $eventName = $data['event'] ?? ($eventType === 'pageview' ? 'pageview' : 'custom_event');
        $eventCategory = $data['category'] ?? ($eventType === 'ecommerce' ? 'ecommerce' : ($eventType === 'conversion' ? 'conversion' : 'general'));

        $event = VisitorEvent::create([
            'property_id' => $property->id,
            'session_id' => $sessionId,
            'visitor_identity_id' => $identity->id,
            'user_id' => $userId,
            'event_type' => $eventType === 'identify' ? 'custom' : $eventType,
            'event_name' => $eventName,
            'category' => $eventCategory,
            'url' => $url,
            'path' => $path,
            'page_title' => $data['title'] ?? null,
            'properties' => $data['properties'] ?? null,
            'created_at' => now(),
        ]);

        // 4. Record Conversion if applicable
        if ($eventType === 'conversion' || !empty($data['conversion_goal'])) {
            $goalName = $data['conversion_goal'] ?? $eventName;
            $revenue = is_numeric($data['revenue'] ?? $data['value'] ?? null) ? max(0, (float) ($data['revenue'] ?? $data['value'])) : 0;
            $currency = strtoupper((string) ($data['currency'] ?? 'UNKNOWN'));

            VisitorConversion::create([
                'property_id' => $property->id,
                'session_id' => $sessionId,
                'visitor_identity_id' => $identity->id,
                'user_id' => $userId,
                'goal_name' => $goalName,
                'revenue' => $revenue,
                'currency' => $currency,
                'order_id' => $data['order_id'] ?? null,
                'metadata' => $data['properties'] ?? null,
                'created_at' => now(),
            ]);
        }

        return [
            'success' => true,
            'property_id' => $property->property_uid,
            'session_id' => $sessionId,
            'anonymous_id' => $anonymousId,
        ];
    }

    /**
     * Link an anonymous visitor session to an authenticated user upon signup/login
     */
    public function linkIdentity(string $propertyUid, string $anonymousId, int $userId): bool
    {
        $property = VisitorProperty::where('property_uid', $propertyUid)->first();
        if (!$property) {
            return false;
        }

        $identity = VisitorIdentity::where('property_id', $property->id)
            ->where('anonymous_id', $anonymousId)
            ->first();

        if ($identity) {
            $identity->update(['user_id' => $userId]);

            VisitorSession::where('property_id', $property->id)
                ->where('visitor_identity_id', $identity->id)
                ->whereNull('user_id')
                ->update(['user_id' => $userId]);

            VisitorEvent::where('property_id', $property->id)
                ->where('visitor_identity_id', $identity->id)
                ->whereNull('user_id')
                ->update(['user_id' => $userId]);

            return true;
        }

        return false;
    }

    /**
     * Anonymize IP address by zeroing the last octet for IPv4 or prefixing for IPv6
     */
    public function anonymizeIp(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts = explode('.', $ip);
            $parts[3] = '0';
            return implode('.', $parts);
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $parts = explode(':', $ip);
            return implode(':', array_slice($parts, 0, 4)) . '::';
        }
        return '0.0.0.0';
    }

    /**
     * Lightweight User-Agent parser for Device, Browser, and OS
     */
    public function parseUserAgent(string $ua): array
    {
        $device = 'desktop';
        if (preg_match('/(tablet|ipad|playbook|silk)|(android(?!.*mobi))/i', $ua)) {
            $device = 'tablet';
        } elseif (preg_match('/Mobile|iP(hone|od)|Android|BlackBerry|IEMobile|Kindle|NetFront|Silk-Accelerated|(hpw|web)OS|Fennec|Minimo|Opera M(obi|ini)|Blazer/i', $ua)) {
            $device = 'mobile';
        }

        $browser = 'Other';
        if (str_contains($ua, 'Edg/')) {
            $browser = 'Edge';
        } elseif (str_contains($ua, 'Chrome/') && !str_contains($ua, 'Chromium/')) {
            $browser = 'Chrome';
        } elseif (str_contains($ua, 'Safari/') && !str_contains($ua, 'Chrome/')) {
            $browser = 'Safari';
        } elseif (str_contains($ua, 'Firefox/')) {
            $browser = 'Firefox';
        } elseif (str_contains($ua, 'Opera') || str_contains($ua, 'OPR/')) {
            $browser = 'Opera';
        }

        $os = 'Other';
        if (str_contains($ua, 'iPhone') || str_contains($ua, 'iPad')) {
            $os = 'iOS';
        } elseif (str_contains($ua, 'Android')) {
            $os = 'Android';
        } elseif (str_contains($ua, 'Windows')) {
            $os = 'Windows';
        } elseif (str_contains($ua, 'Macintosh') || str_contains($ua, 'Mac OS X')) {
            $os = 'macOS';
        } elseif (str_contains($ua, 'Linux')) {
            $os = 'Linux';
        }

        return ['device' => $device, 'browser' => $browser, 'os' => $os];
    }

    /**
     * Resolve coarse geolocation
     */
    private function resolveGeo(Request $request, array $data): array
    {
        // Use only trusted edge headers. Never accept client-supplied geo values
        // or invent a default country/city when location is unavailable.
        $countryCode = strtoupper((string) ($request->header('CF-IPCountry') ?: $request->header('X-Country-Code') ?: ''));
        $countryNames = ['SA' => 'Saudi Arabia', 'AE' => 'United Arab Emirates', 'EG' => 'Egypt', 'KW' => 'Kuwait', 'QA' => 'Qatar', 'BH' => 'Bahrain', 'OM' => 'Oman', 'US' => 'United States', 'GB' => 'United Kingdom'];
        $country = $countryNames[$countryCode] ?? null;
        $city = $request->header('CF-IPCity') ?: null;

        return [
            'country' => $country,
            'country_code' => $countryCode,
            'city' => $city,
        ];
    }

}
