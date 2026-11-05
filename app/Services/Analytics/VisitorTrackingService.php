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
            // Auto-provision if it's the internal property or customer store
            $property = $this->autoProvisionProperty($propertyUid, $data, $request);
        }

        if (!$property->is_active) {
            return ['success' => false, 'error' => 'Property is inactive'];
        }

        // Domain validation if configured
        $origin = $request->header('Origin') ?: $request->header('Referer');
        if ($origin && !empty($property->allowed_domains)) {
            $parsedHost = parse_url($origin, PHP_URL_HOST);
            if ($parsedHost && !in_array($parsedHost, $property->allowed_domains, true)) {
                Log::warning('Analytics domain mismatch rejected', [
                    'property' => $property->property_uid,
                    'host' => $parsedHost,
                    'allowed' => $property->allowed_domains,
                ]);
            }
        }

        $anonymousId = $data['anonymous_id'] ?? $data['visitor_id'] ?? Str::uuid()->toString();
        $sessionId = $data['session_id'] ?? Str::uuid()->toString();
        $userId = $data['user_id'] ?? null;

        // Anonymize IP
        $rawIp = $request->ip() ?: '127.0.0.1';
        $ipHash = hash('sha256', $this->anonymizeIp($rawIp) . date('Y-m-d'));

        // Parse User-Agent
        $ua = $request->userAgent() ?: ($data['user_agent'] ?? '');
        $clientInfo = $this->parseUserAgent($ua);

        // Geolocation (Coarse country / city)
        $geo = $this->resolveGeo($request, $data);

        // 1. Resolve or create Identity
        $identity = VisitorIdentity::firstOrCreate(
            ['property_id' => $property->id, 'anonymous_id' => $anonymousId],
            [
                'user_id' => $userId,
                'first_seen_at' => now(),
                'last_seen_at' => now(),
                'traits' => $data['traits'] ?? null,
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

        $referrer = $data['referrer'] ?? null;
        $referrerDomain = $referrer ? (parse_url($referrer, PHP_URL_HOST) ?: null) : null;
        $url = $data['url'] ?? $request->fullUrl();
        $path = $data['path'] ?? (parse_url($url, PHP_URL_PATH) ?: '/');

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
                'device_type' => $data['device_type'] ?? $clientInfo['device'],
                'browser' => $data['browser'] ?? $clientInfo['browser'],
                'os' => $data['os'] ?? $clientInfo['os'],
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
                'is_bounce' => true,
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
                'is_bounce' => ($session->pageviews_count <= 1 && $session->events_count <= 2),
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
            'event_type' => $eventType,
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
            $revenue = (float) ($data['revenue'] ?? $data['value'] ?? 0);
            $currency = $data['currency'] ?? 'SAR';

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
        $country = $data['country'] ?? $request->header('CF-IPCountry') ?? $request->header('X-Country-Code') ?? 'Saudi Arabia';
        $countryCode = $data['country_code'] ?? ($country === 'Saudi Arabia' ? 'SA' : ($country === 'UAE' ? 'AE' : 'SA'));
        $city = $data['city'] ?? $request->header('CF-IPCity') ?? ($countryCode === 'SA' ? 'Riyadh' : 'Dubai');

        return [
            'country' => $country,
            'country_code' => $countryCode,
            'city' => $city,
        ];
    }

    private function autoProvisionProperty(string $propertyUid, array $data, Request $request): VisitorProperty
    {
        $host = $request->header('Origin') ?: $request->header('Referer') ?: 'nazbiz.io';
        $domain = parse_url($host, PHP_URL_HOST) ?: 'nazbiz.io';

        return VisitorProperty::create([
            'property_uid' => $propertyUid,
            'name' => $data['property_name'] ?? ($domain === 'nazbiz.io' ? 'Nazeer Business (Internal)' : $domain),
            'domain' => $domain,
            'allowed_domains' => [$domain],
            'type' => $domain === 'nazbiz.io' ? 'internal' : 'customer_store',
            'platform' => $data['platform'] ?? ($domain === 'nazbiz.io' ? 'nextjs' : 'custom'),
            'settings' => ['anonymize_ip' => true, 'respect_dnt' => true],
            'is_active' => true,
            'verified_at' => now(),
        ]);
    }
}
