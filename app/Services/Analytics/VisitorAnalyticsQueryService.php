<?php

namespace App\Services\Analytics;

use App\Models\VisitorConversion;
use App\Models\VisitorEvent;
use App\Models\VisitorProperty;
use App\Models\VisitorSession;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Analytics calculations are based only on recorded tracking rows. Missing
 * instrumentation is returned as null/empty data; it is never estimated.
 */
class VisitorAnalyticsQueryService
{
    public function getProperties(): array
    {
        $activeSince = now()->subMinutes(5);

        return VisitorProperty::query()
            ->where('is_active', true)
            ->withCount(['sessions as activeSessions' => fn ($query) => $query->where('last_activity_at', '>=', $activeSince)])
            ->orderBy('name')
            ->get()
            ->map(fn (VisitorProperty $property) => [
                'id' => $property->property_uid,
                'db_id' => $property->id,
                'name' => $property->name,
                'domain' => $property->domain,
                'allowed_domains' => $property->allowed_domains ?? [],
                'type' => $property->type,
                'platform' => $property->platform,
                'activeSessions' => (int) $property->activeSessions,
                'verified_at' => $property->verified_at,
            ])->all();
    }

    public function resolveDateRange(string $preset): array
    {
        $today = now()->startOfDay();

        return match ($preset) {
            'today' => $this->todayRange($today),
            'yesterday' => [
                'start' => $today->copy()->subDay(),
                'end' => $today->copy()->subSecond(),
                'prev_start' => $today->copy()->subDays(2),
                'prev_end' => $today->copy()->subDay()->subSecond(),
                'groupBy' => 'hour',
                'bucketCount' => 24,
            ],
            '7d' => $this->completeDayRange($today, 7),
            '90d' => $this->completeDayRange($today, 90),
            default => $this->completeDayRange($today, 30),
        };
    }

    private function todayRange($today): array
    {
        $now = now();

        return [
            'start' => $today->copy(),
            'end' => $now,
            'prev_start' => $today->copy()->subDay(),
            'prev_end' => $today->copy()->subDay()->add($now->diff($today)),
            'groupBy' => 'hour',
            'bucketCount' => (int) $now->format('G') + 1,
        ];
    }

    private function completeDayRange($today, int $days): array
    {
        return [
            'start' => $today->copy()->subDays($days),
            'end' => $today->copy()->subSecond(),
            'prev_start' => $today->copy()->subDays($days * 2),
            'prev_end' => $today->copy()->subDays($days)->subSecond(),
            'groupBy' => 'day',
            'bucketCount' => $days,
        ];
    }

    public function getDashboardMetrics(?string $propertyUid = null, string $preset = '30d'): array
    {
        $property = $this->resolveProperty($propertyUid);
        $propertyId = $property?->id;
        $range = $this->resolveDateRange($preset);

        $sessions = fn (array $period) => VisitorSession::query()
            ->when($propertyId !== null, fn ($query) => $query->where('property_id', $propertyId))
            ->whereBetween('started_at', [$period['start'], $period['end']]);
        $events = fn (array $period) => VisitorEvent::query()
            ->when($propertyId !== null, fn ($query) => $query->where('property_id', $propertyId))
            ->whereBetween('created_at', [$period['start'], $period['end']]);
        $conversions = fn (array $period) => VisitorConversion::query()
            ->when($propertyId !== null, fn ($query) => $query->where('property_id', $propertyId))
            ->whereBetween('created_at', [$period['start'], $period['end']]);

        $currentSessionQuery = $sessions($range);
        $previousSessionQuery = $sessions(['start' => $range['prev_start'], 'end' => $range['prev_end']]);
        $currentSessions = (clone $currentSessionQuery)->distinct('id')->count('id');
        $previousSessions = (clone $previousSessionQuery)->distinct('id')->count('id');
        $currentVisitors = (clone $currentSessionQuery)->whereNotNull('visitor_identity_id')->distinct()->count('visitor_identity_id');
        $previousVisitors = (clone $previousSessionQuery)->whereNotNull('visitor_identity_id')->distinct()->count('visitor_identity_id');
        $currentPageviews = $events($range)->where('event_type', 'pageview')->count();
        $previousPageviews = $events(['start' => $range['prev_start'], 'end' => $range['prev_end']])->where('event_type', 'pageview')->count();

        // A "single-page session" is observable from collected pageview events.
        // It is deliberately not called a bounce rate because engagement intent
        // and session completion are not available in the current event schema.
        $singlePageSessions = $this->countSinglePageSessions($currentSessionQuery, $range);
        $previousSinglePageSessions = $this->countSinglePageSessions($previousSessionQuery, [
            'start' => $range['prev_start'],
            'end' => $range['prev_end'],
        ]);
        $singlePageRate = $currentSessions > 0 ? round($singlePageSessions / $currentSessions * 100, 1) : null;
        $previousSinglePageRate = $previousSessions > 0 ? round($previousSinglePageSessions / $previousSessions * 100, 1) : null;

        // Zero is the initial placeholder until a second event records elapsed
        // time. Exclude it so unknown durations are not reported as 0 seconds.
        $currentObservedDuration = (clone $currentSessionQuery)->where('duration_seconds', '>', 0)->avg('duration_seconds');
        $previousObservedDuration = (clone $previousSessionQuery)->where('duration_seconds', '>', 0)->avg('duration_seconds');
        $currentObservedDuration = $currentObservedDuration === null ? null : (int) round((float) $currentObservedDuration);
        $previousObservedDuration = $previousObservedDuration === null ? null : (int) round((float) $previousObservedDuration);

        $currentConversions = $conversions($range);
        $previousConversions = $conversions(['start' => $range['prev_start'], 'end' => $range['prev_end']]);
        $conversionCount = (clone $currentConversions)->count();
        $previousConversionCount = (clone $previousConversions)->count();
        $convertedVisitors = (clone $currentConversions)->whereNotNull('visitor_identity_id')
            ->whereIn('visitor_identity_id', (clone $currentSessionQuery)->whereNotNull('visitor_identity_id')->select('visitor_identity_id'))
            ->distinct()->count('visitor_identity_id');
        $previousConvertedVisitors = (clone $previousConversions)->whereNotNull('visitor_identity_id')
            ->whereIn('visitor_identity_id', (clone $previousSessionQuery)->whereNotNull('visitor_identity_id')->select('visitor_identity_id'))
            ->distinct()->count('visitor_identity_id');
        $conversionRate = $currentVisitors > 0 ? round($convertedVisitors / $currentVisitors * 100, 2) : null;
        $previousConversionRate = $previousVisitors > 0 ? round($previousConvertedVisitors / $previousVisitors * 100, 2) : null;

        $ecommerce = $this->buildEcommerce($propertyId, $range);
        $timeSeries = $this->buildTimeSeries($propertyId, $range);
        $sources = $this->buildTrafficSources($propertyId, $range);
        $devices = $this->buildDeviceDistribution($propertyId, $range);
        $topPages = $this->buildTopPages($propertyId, $range);
        $audience = $this->buildAudience($propertyId, $range);
        $eventRows = $this->buildEvents($propertyId, $range);
        $funnel = $property ? $this->buildFunnel($property, $range) : [];

        return [
            'property' => $property ? [
                'id' => $property->property_uid,
                'name' => $property->name,
                'type' => $property->type,
            ] : ['id' => 'prop_all_customers', 'name' => 'All properties', 'type' => 'aggregate'],
            'period' => [
                'preset' => $preset,
                'from' => $range['start']->toIso8601String(),
                'to' => $range['end']->toIso8601String(),
                'comparison_from' => $range['prev_start']->toIso8601String(),
                'comparison_to' => $range['prev_end']->toIso8601String(),
                'timezone' => config('app.timezone'),
            ],
            'kpis' => [
                'visitors' => $currentVisitors,
                'visitorsTrend' => $this->trend($currentVisitors, $previousVisitors),
                'sessions' => $currentSessions,
                'sessionsTrend' => $this->trend($currentSessions, $previousSessions),
                'pageviews' => $currentPageviews,
                'pageviewsTrend' => $this->trend($currentPageviews, $previousPageviews),
                'singlePageSessionRate' => $singlePageRate === null ? null : $singlePageRate . '%',
                'singlePageSessionRateTrend' => $this->trend($singlePageRate, $previousSinglePageRate),
                'observedDuration' => $currentObservedDuration === null ? null : sprintf('%dm %02ds', intdiv($currentObservedDuration, 60), $currentObservedDuration % 60),
                'observedDurationTrend' => $this->trend($currentObservedDuration, $previousObservedDuration),
                'conversionRate' => $conversionRate === null ? null : $conversionRate . '%',
                'conversionTrend' => $this->trend($conversionRate, $previousConversionRate),
                'conversions' => $conversionCount,
                'conversionCountTrend' => $this->trend($conversionCount, $previousConversionCount),
                'revenue' => $ecommerce['grossRevenue'],
                'revenueTrend' => $ecommerce['revenueTrend'],
            ],
            'timeSeries' => $timeSeries,
            'trafficSources' => $sources['channels'],
            'topReferrers' => $sources['referrers'],
            'utmCampaigns' => $sources['utmCampaigns'],
            'deviceData' => $devices,
            'topPages' => $topPages,
            'audience' => $audience,
            'events' => $eventRows,
            'funnel' => $funnel,
            'funnelAvailable' => $property !== null,
            'ecommerce' => $ecommerce,
        ];
    }

    private function countSinglePageSessions($sessions, array $period): int
    {
        return (int) (clone $sessions)->whereExists(function ($query) use ($period) {
            $query->selectRaw('1')
                ->from('visitor_events')
                ->whereColumn('visitor_events.property_id', 'visitor_sessions.property_id')
                ->whereColumn('visitor_events.session_id', 'visitor_sessions.session_id')
                ->where('visitor_events.event_type', 'pageview')
                ->whereBetween('visitor_events.created_at', [$period['start'], $period['end']])
                ->groupBy('visitor_events.property_id', 'visitor_events.session_id')
                ->havingRaw('COUNT(*) = 1');
        })->count('visitor_sessions.id');
    }

    public function getLiveFeed(?string $propertyUid = null): array
    {
        $property = $this->resolveProperty($propertyUid);
        $propertyId = $property?->id;
        $now = now();
        $activeSince = $now->copy()->subMinutes(5);
        $activeQuery = VisitorSession::query()
            ->when($propertyId !== null, fn ($query) => $query->where('property_id', $propertyId))
            ->where('last_activity_at', '>=', $activeSince);

        $activeCount = (clone $activeQuery)->distinct('id')->count('id');
        $activeSessions = (clone $activeQuery)->latest('last_activity_at')->limit(15)->get();

        $from = $now->copy()->subMinutes(29)->startOfMinute();
        $eventBuckets = VisitorEvent::query()
            ->when($propertyId !== null, fn ($query) => $query->where('property_id', $propertyId))
            ->where('created_at', '>=', $from)
            ->where('created_at', '<=', $now)
            ->selectRaw("DATE_FORMAT(created_at, '%Y-%m-%d %H:%i') as minute_bucket, count(*) as event_count")
            ->groupBy('minute_bucket')
            ->pluck('event_count', 'minute_bucket');

        $histogram = [];
        for ($i = 0; $i < 30; $i++) {
            $minute = $from->copy()->addMinutes($i);
            $histogram[] = [
                'label' => $minute->format('H:i'),
                'count' => (int) ($eventBuckets->get($minute->format('Y-m-d H:i')) ?? 0),
            ];
        }

        $sessions = $activeSessions->map(function (VisitorSession $session) {
            $lastEvent = VisitorEvent::query()
                ->where('property_id', $session->property_id)
                ->where('session_id', $session->session_id)
                ->latest('created_at')
                ->first(['event_name', 'path']);

            return [
                'id' => 'vis_' . substr(hash('sha256', $session->property_id . ':' . $session->session_id), 0, 8),
                'path' => $lastEvent?->path ?: null,
                'entry' => $session->entry_url ? (parse_url($session->entry_url, PHP_URL_PATH) ?: '/') : null,
                'country' => $session->country,
                'flag' => $this->countryFlag($session->country_code),
                'city' => $session->city,
                'device' => trim(implode(' / ', array_filter([$session->device_type, $session->browser]))) ?: null,
                'referrer' => $session->referrer_domain,
                'activeTime' => sprintf('%dm %02ds', intdiv((int) $session->duration_seconds, 60), (int) $session->duration_seconds % 60),
                'lastAction' => $lastEvent?->event_name,
                'lastActivityAt' => $session->last_activity_at?->toIso8601String(),
            ];
        })->all();

        return ['activeCount' => $activeCount, 'histogram' => $histogram, 'sessions' => $sessions];
    }

    private function resolveProperty(?string $propertyUid): ?VisitorProperty
    {
        if ($propertyUid === null || $propertyUid === '' || $propertyUid === 'prop_all_customers') {
            return null;
        }

        $property = VisitorProperty::query()->where('property_uid', $propertyUid)->where('is_active', true)->first();
        if (!$property) {
            throw (new ModelNotFoundException())->setModel(VisitorProperty::class, [$propertyUid]);
        }

        return $property;
    }

    private function trend(float|int|null $current, float|int|null $previous): ?string
    {
        if ($current === null || $previous === null || $previous == 0) {
            return null;
        }

        $change = (($current - $previous) / abs($previous)) * 100;
        return sprintf('%s%.1f%%', $change > 0 ? '+' : '', $change);
    }

    private function buildTimeSeries(?int $propertyId, array $range): array
    {
        $current = $this->aggregateSeries($propertyId, $range['start'], $range['end'], $range['groupBy']);
        $previous = $this->aggregateSeries($propertyId, $range['prev_start'], $range['prev_end'], $range['groupBy']);
        $points = [];

        for ($i = 0; $i < $range['bucketCount']; $i++) {
            $cursor = $range['start']->copy()->add($range['groupBy'] === 'hour' ? $i . ' hours' : $i . ' days');
            $previousCursor = $range['prev_start']->copy()->add($range['groupBy'] === 'hour' ? $i . ' hours' : $i . ' days');
            $key = $this->bucketKey($cursor, $range['groupBy']);
            $previousKey = $this->bucketKey($previousCursor, $range['groupBy']);
            $currentRow = $current[$key] ?? [];
            $previousRow = $previous[$previousKey] ?? [];

            $points[] = [
                'label' => $range['groupBy'] === 'hour' ? $cursor->format('H:00') : $cursor->format('M j'),
                'visitors' => (int) ($currentRow['visitors'] ?? 0),
                'sessions' => (int) ($currentRow['sessions'] ?? 0),
                'pageviews' => (int) ($currentRow['pageviews'] ?? 0),
                'prevVisitors' => (int) ($previousRow['visitors'] ?? 0),
                'prevSessions' => (int) ($previousRow['sessions'] ?? 0),
                'prevPageviews' => (int) ($previousRow['pageviews'] ?? 0),
            ];
        }

        return $points;
    }

    private function aggregateSeries(?int $propertyId, $start, $end, string $groupBy): array
    {
        $format = $groupBy === 'hour' ? '%Y-%m-%d %H:00' : '%Y-%m-%d';
        $sessionRows = VisitorSession::query()
            ->when($propertyId !== null, fn ($query) => $query->where('property_id', $propertyId))
            ->whereBetween('started_at', [$start, $end])
            ->selectRaw("DATE_FORMAT(started_at, '{$format}') as bucket, count(distinct id) as sessions, count(distinct visitor_identity_id) as visitors")
            ->groupBy('bucket')->get()->keyBy('bucket');
        $pageviewRows = VisitorEvent::query()
            ->when($propertyId !== null, fn ($query) => $query->where('property_id', $propertyId))
            ->where('event_type', 'pageview')->whereBetween('created_at', [$start, $end])
            ->selectRaw("DATE_FORMAT(created_at, '{$format}') as bucket, count(*) as pageviews")
            ->groupBy('bucket')->pluck('pageviews', 'bucket');

        $result = [];
        foreach ($sessionRows as $key => $row) {
            $result[$key] = [
                'sessions' => (int) $row->sessions,
                'visitors' => (int) $row->visitors,
                'pageviews' => (int) ($pageviewRows->get($key) ?? 0),
            ];
        }
        foreach ($pageviewRows as $key => $count) {
            $result[$key] ??= ['sessions' => 0, 'visitors' => 0, 'pageviews' => 0];
            $result[$key]['pageviews'] = (int) $count;
        }

        return $result;
    }

    private function bucketKey($date, string $groupBy): string
    {
        return $groupBy === 'hour' ? $date->format('Y-m-d H:00') : $date->format('Y-m-d');
    }

    private function buildTrafficSources(?int $propertyId, array $range): array
    {
        $sessions = VisitorSession::query()
            ->when($propertyId !== null, fn ($query) => $query->where('property_id', $propertyId))
            ->whereBetween('started_at', [$range['start'], $range['end']])
            ->get(['id', 'property_id', 'session_id', 'visitor_identity_id', 'referrer_domain', 'utm_source', 'utm_medium', 'utm_campaign']);

        $groups = ['Organic Search' => 0, 'Direct Traffic' => 0, 'Social Media' => 0, 'Referral Links' => 0, 'Paid Campaign' => 0, 'Email' => 0];
        $referrerGroups = [];
        $campaignGroups = [];

        foreach ($sessions as $session) {
            $referrer = strtolower((string) $session->referrer_domain);
            $source = strtolower((string) $session->utm_source);
            $medium = strtolower((string) $session->utm_medium);
            $sourceText = $source . ' ' . $referrer;

            if (in_array($medium, ['cpc', 'ppc', 'paid', 'paid_social', 'display'], true)) {
                $bucket = 'Paid Campaign';
            } elseif ($medium === 'email' || str_contains($sourceText, 'email')) {
                $bucket = 'Email';
            } elseif (preg_match('/google|bing|yahoo|duckduckgo/', $sourceText)) {
                $bucket = 'Organic Search';
            } elseif (preg_match('/twitter|x\.com|facebook|instagram|linkedin|tiktok|youtube/', $sourceText)) {
                $bucket = 'Social Media';
            } elseif ($referrer === '' && $source === '') {
                $bucket = 'Direct Traffic';
            } elseif ($referrer !== '' || $source !== '') {
                $bucket = 'Referral Links';
            } else {
                continue;
            }

            $groups[$bucket]++;
            if ($session->referrer_domain) {
                $referrerKey = $session->referrer_domain;
                $referrerGroups[$referrerKey]['sessions'] = ($referrerGroups[$referrerKey]['sessions'] ?? 0) + 1;
                $referrerGroups[$referrerKey]['session_keys'][] = $session->property_id . ':' . $session->session_id;
            }
            if ($session->utm_campaign) {
                $campaignKey = $session->utm_campaign . '|' . ($session->utm_medium ?: 'unknown');
                $campaignGroups[$campaignKey]['campaign'] = $session->utm_campaign;
                $campaignGroups[$campaignKey]['medium'] = $session->utm_medium ?: 'unknown';
                $campaignGroups[$campaignKey]['sessions'] = ($campaignGroups[$campaignKey]['sessions'] ?? 0) + 1;
                $campaignGroups[$campaignKey]['session_keys'][] = $session->property_id . ':' . $session->session_id;
            }
        }

        $total = array_sum($groups);
        $colors = ['Organic Search' => '#10b981', 'Direct Traffic' => '#06b6d4', 'Social Media' => '#8b5cf6', 'Referral Links' => '#f59e0b', 'Paid Campaign' => '#ec4899', 'Email' => '#3b82f6'];
        $channels = [];
        foreach ($groups as $name => $count) {
            $channels[] = ['name' => $name, 'sessions' => $count, 'percent' => $total > 0 ? round($count / $total * 100, 1) : 0, 'color' => $colors[$name]];
        }

        $conversionSessionKeys = VisitorConversion::query()
            ->when($propertyId !== null, fn ($query) => $query->where('property_id', $propertyId))
            ->whereBetween('created_at', [$range['start'], $range['end']])
            ->get(['property_id', 'session_id'])
            ->mapWithKeys(fn ($row) => [$row->property_id . ':' . $row->session_id => true]);

        $referrers = [];
        foreach ($referrerGroups as $domain => $group) {
            $conversions = collect($group['session_keys'])->unique()->filter(fn ($key) => isset($conversionSessionKeys[$key]))->count();
            $referrers[] = [
                'domain' => $domain,
                'sessions' => $group['sessions'],
                'bounce' => null,
                'conv' => $group['sessions'] > 0 ? round($conversions / $group['sessions'] * 100, 1) . '%' : null,
            ];
        }
        usort($referrers, fn ($a, $b) => $b['sessions'] <=> $a['sessions']);

        $utmCampaigns = [];
        foreach ($campaignGroups as $group) {
            $keys = collect($group['session_keys'])->unique();
            $conversionCount = $keys->filter(fn ($key) => isset($conversionSessionKeys[$key]))->count();
            $utmCampaigns[] = [
                'campaign' => $group['campaign'],
                'medium' => $group['medium'],
                'sessions' => $keys->count(),
                'conversions' => $conversionCount,
                'conversionRate' => $keys->isNotEmpty() ? round($conversionCount / $keys->count() * 100, 1) . '%' : null,
            ];
        }
        usort($utmCampaigns, fn ($a, $b) => $b['sessions'] <=> $a['sessions']);

        return [
            'channels' => $channels,
            'referrers' => array_slice($referrers, 0, 6),
            'utmCampaigns' => array_slice($utmCampaigns, 0, 10),
        ];
    }

    private function buildDeviceDistribution(?int $propertyId, array $range): array
    {
        $rows = VisitorSession::query()
            ->when($propertyId !== null, fn ($query) => $query->where('property_id', $propertyId))
            ->whereBetween('started_at', [$range['start'], $range['end']])
            ->selectRaw("COALESCE(NULLIF(device_type, ''), 'Unknown') as device, count(distinct id) as sessions")
            ->groupBy('device')->orderByDesc('sessions')->get();
        $total = (int) $rows->sum('sessions');
        $colors = ['mobile' => '#06b6d4', 'desktop' => '#8b5cf6', 'tablet' => '#f59e0b', 'unknown' => '#64748b'];

        return $rows->map(fn ($row) => [
            'name' => $row->device,
            'value' => $total > 0 ? round($row->sessions / $total * 100, 1) : 0,
            'count' => (int) $row->sessions,
            'color' => $colors[strtolower($row->device)] ?? '#64748b',
        ])->all();
    }

    private function buildTopPages(?int $propertyId, array $range): array
    {
        $rows = VisitorEvent::query()
            ->when($propertyId !== null, fn ($query) => $query->where('property_id', $propertyId))
            ->where('event_type', 'pageview')
            ->whereBetween('created_at', [$range['start'], $range['end']])
            ->selectRaw('path, COALESCE(NULLIF(page_title, ""), path) as title, count(*) as pageviews, count(distinct visitor_identity_id) as unique_visitors')
            ->groupBy('path', 'title')->orderByDesc('pageviews')->limit(10)->get();

        return $rows->map(fn ($row) => [
            'path' => $row->path,
            'title' => $row->title,
            'pageviews' => (int) $row->pageviews,
            'unique' => (int) $row->unique_visitors,
            'avgTime' => null,
            'bounce' => null,
            'exit' => null,
        ])->all();
    }

    private function buildAudience(?int $propertyId, array $range): array
    {
        $base = VisitorSession::query()
            ->when($propertyId !== null, fn ($query) => $query->where('property_id', $propertyId))
            ->whereBetween('started_at', [$range['start'], $range['end']]);

        $countries = (clone $base)->selectRaw("COALESCE(NULLIF(country, ''), 'Unknown') as country, country_code, count(distinct id) as sessions")
            ->groupBy('country', 'country_code')->orderByDesc('sessions')->get();
        $countryTotal = (int) $countries->sum('sessions');
        $browsers = (clone $base)->selectRaw("COALESCE(NULLIF(browser, ''), 'Unknown') as name, count(distinct id) as sessions")
            ->groupBy('name')->orderByDesc('sessions')->get();
        $browserTotal = (int) $browsers->sum('sessions');
        $operatingSystems = (clone $base)->selectRaw("COALESCE(NULLIF(os, ''), 'Unknown') as name, count(distinct id) as sessions")
            ->groupBy('name')->orderByDesc('sessions')->get();
        $osTotal = (int) $operatingSystems->sum('sessions');
        $flags = ['SA' => '🇸🇦', 'AE' => '🇦🇪', 'EG' => '🇪🇬', 'KW' => '🇰🇼', 'QA' => '🇶🇦', 'US' => '🇺🇸', 'GB' => '🇬🇧'];

        return [
            'countries' => $countries->take(10)->map(fn ($row) => [
                'country' => $row->country,
                'ar' => $row->country === 'Saudi Arabia' ? 'المملكة العربية السعودية' : ($row->country === 'UAE' ? 'الإمارات العربية المتحدة' : $row->country),
                'flag' => $flags[$row->country_code] ?? '🌐',
                'sessions' => number_format((int) $row->sessions),
                'pct' => $countryTotal > 0 ? round($row->sessions / $countryTotal * 100, 1) : 0,
            ])->all(),
            'browsers' => $browsers->map(fn ($row) => ['name' => $row->name, 'pct' => ($browserTotal > 0 ? round($row->sessions / $browserTotal * 100, 1) : 0) . '%'])->all(),
            'os' => $operatingSystems->map(fn ($row) => ['name' => $row->name, 'pct' => ($osTotal > 0 ? round($row->sessions / $osTotal * 100, 1) : 0) . '%'])->all(),
        ];
    }

    private function buildEvents(?int $propertyId, array $range): array
    {
        return VisitorEvent::query()
            ->when($propertyId !== null, fn ($query) => $query->where('property_id', $propertyId))
            ->whereBetween('created_at', [$range['start'], $range['end']])
            ->where('event_type', '!=', 'pageview')
            ->selectRaw("event_name as name, category, count(*) as count, count(distinct visitor_identity_id) as unique_count, count(distinct CONCAT(property_id, ':', session_id)) as session_count")
            ->groupBy('name', 'category')->orderByDesc('count')->limit(20)->get()
            ->map(fn ($row) => [
                'name' => $row->name,
                'category' => $row->category ?: 'general',
                'count' => (int) $row->count,
                'unique' => (int) $row->unique_count,
                'sessions' => (int) $row->session_count,
                'perSession' => $row->session_count > 0 ? round($row->count / $row->session_count, 2) : null,
                'status' => 'tracked',
            ])->all();
    }

    private function buildFunnel(VisitorProperty $property, array $range): array
    {
        $stages = $property->type === 'internal'
            ? [
                ['label' => 'Pricing viewed', 'table' => 'visitor_events', 'type' => 'pageview', 'column' => 'path', 'values' => ['/pricing']],
                ['label' => 'Registration viewed', 'table' => 'visitor_events', 'type' => 'pageview', 'column' => 'path', 'values' => ['/register']],
                ['label' => 'Account created', 'table' => 'visitor_conversions', 'column' => 'goal_name', 'values' => ['account_created', 'signup_completed', 'user_registered']],
                ['label' => 'Subscription paid', 'table' => 'visitor_conversions', 'column' => 'goal_name', 'values' => ['subscription_paid', 'subscription_activated', 'payment_succeeded']],
            ]
            : [
                ['label' => 'Product viewed', 'table' => 'visitor_events', 'type' => 'pageview', 'column' => 'path_prefix', 'values' => ['/products/']],
                ['label' => 'Added to cart', 'table' => 'visitor_events', 'type' => null, 'column' => 'event_name', 'values' => ['add_to_cart', 'product_added_to_cart', 'cart_item_added']],
                ['label' => 'Checkout started', 'table' => 'visitor_events', 'type' => null, 'column' => 'event_name', 'values' => ['checkout_started', 'begin_checkout']],
                ['label' => 'Order completed', 'table' => 'visitor_conversions', 'column' => 'goal_name', 'values' => ['order_placed', 'order_completed', 'purchase']],
            ];

        $base = VisitorSession::query()->where('property_id', $property->id)->whereBetween('started_at', [$range['start'], $range['end']]);
        $counts = [(clone $base)->distinct('id')->count('id')];
        foreach ($stages as $index => $_stage) {
            $query = (clone $base);
            $this->whereFunnelStage($query, 's', $index, $stages, $range);
            $counts[] = $query->distinct('id')->count('id');
        }

        $labels = [$property->type === 'internal' ? 'Site sessions' : 'Store sessions', ...array_column($stages, 'label')];
        $result = [];
        $previous = null;
        foreach ($counts as $index => $count) {
            $rate = $counts[0] > 0 ? round($count / $counts[0] * 100, 1) : null;
            $dropoff = $index === 0 || $previous === null || $previous === 0
                ? null
                : round(max(0, ($previous - $count) / $previous * 100), 1) . '%';
            $result[] = [
                'step' => ($index + 1) . '. ' . $labels[$index],
                'sessions' => (int) $count,
                'rate' => $rate,
                'dropoff' => $dropoff,
            ];
            $previous = $count;
        }

        return $result;
    }

    private function whereFunnelStage($query, string $sessionAlias, int $stageIndex, array $stages, array $range): void
    {
        $query->whereExists(function ($stageQuery) use ($sessionAlias, $stageIndex, $stages, $range) {
            $stage = $stages[$stageIndex];
            $alias = 'stage_' . $stageIndex;
            $stageQuery->selectRaw('1')->from($stage['table'] . ' as ' . $alias)
                ->whereColumn($alias . '.property_id', $sessionAlias . '.property_id')
                ->whereColumn($alias . '.session_id', $sessionAlias . '.session_id')
                ->whereBetween($alias . '.created_at', [$range['start'], $range['end']]);
            $this->applyStageCondition($stageQuery, $alias, $stage);

            if ($stageIndex > 0) {
                $stageQuery->whereExists(function ($previousQuery) use ($alias, $stageIndex, $stages, $range) {
                    $previousStage = $stages[$stageIndex - 1];
                    $previousAlias = 'stage_' . ($stageIndex - 1);
                    $previousQuery->selectRaw('1')->from($previousStage['table'] . ' as ' . $previousAlias)
                        ->whereColumn($previousAlias . '.property_id', $alias . '.property_id')
                        ->whereColumn($previousAlias . '.session_id', $alias . '.session_id')
                        ->whereColumn($previousAlias . '.created_at', '<=', $alias . '.created_at')
                        ->whereBetween($previousAlias . '.created_at', [$range['start'], $range['end']]);
                    $this->applyStageCondition($previousQuery, $previousAlias, $previousStage);
                    if ($stageIndex > 1) {
                        // Requiring the preceding stage chain makes each step a
                        // sequential cohort, rather than unrelated event totals.
                        $this->whereFunnelStage($previousQuery, $previousAlias, $stageIndex - 2, $stages, $range);
                    }
                });
            }
        });
    }

    private function applyStageCondition($query, string $alias, array $stage): void
    {
        if (isset($stage['type']) && $stage['type'] !== null) {
            $query->where($alias . '.event_type', $stage['type']);
        }
        if ($stage['column'] === 'path_prefix') {
            $query->where($alias . '.path', 'like', $stage['values'][0] . '%');
        } else {
            $query->whereIn($alias . '.' . $stage['column'], $stage['values']);
        }
    }

    private function buildEcommerce(?int $propertyId, array $range): array
    {
        $orderGoals = ['order_placed', 'order_completed', 'purchase'];
        $orders = VisitorConversion::query()
            ->when($propertyId !== null, fn ($query) => $query->where('property_id', $propertyId))
            ->whereBetween('created_at', [$range['start'], $range['end']])
            ->where(function ($query) use ($orderGoals) {
                $query->whereNotNull('order_id')->orWhereIn('goal_name', $orderGoals);
            })
            ->get(['id', 'property_id', 'session_id', 'visitor_identity_id', 'goal_name', 'revenue', 'currency', 'order_id', 'created_at'])
            ->sortByDesc('created_at');

        $distinctOrders = $orders->unique(fn ($row) => $row->property_id . ':' . ($row->order_id ?: 'conversion-' . $row->id));
        $pricedOrders = $distinctOrders->filter(fn ($row) => preg_match('/^[A-Z]{3}$/', strtoupper((string) $row->currency)) && strtoupper((string) $row->currency) !== 'UNKNOWN');
        $revenueGroups = $pricedOrders->groupBy(fn ($row) => strtoupper($row->currency));
        $revenueByCurrency = $revenueGroups->map(fn ($items, $currency) => [
            'currency' => $currency,
            'amount' => round((float) $items->sum('revenue'), 2),
            'orders' => $items->count(),
            'averageOrderValue' => $items->count() > 0 ? round((float) $items->sum('revenue') / $items->count(), 2) : 0,
        ])->values()->all();

        $cartNames = ['add_to_cart', 'product_added_to_cart', 'cart_item_added'];
        $cartSessionsQuery = VisitorEvent::query()
            ->when($propertyId !== null, fn ($query) => $query->where('property_id', $propertyId))
            ->whereBetween('created_at', [$range['start'], $range['end']])
            ->whereIn('event_name', $cartNames);
        $cartSessions = (int) (clone $cartSessionsQuery)
            ->selectRaw("COUNT(DISTINCT CONCAT(property_id, ':', session_id)) as aggregate")->value('aggregate');
        $completedCartSessions = 0;
        if ($cartSessions > 0) {
            $completedCartSessions = (int) VisitorConversion::query()
                ->when($propertyId !== null, fn ($query) => $query->where('visitor_conversions.property_id', $propertyId))
                ->whereBetween('visitor_conversions.created_at', [$range['start'], $range['end']])
                ->where(function ($query) use ($orderGoals) {
                    $query->whereNotNull('visitor_conversions.order_id')->orWhereIn('visitor_conversions.goal_name', $orderGoals);
                })
                ->join('visitor_events as cart_event', function ($join) {
                    $join->on('cart_event.property_id', '=', 'visitor_conversions.property_id')
                        ->on('cart_event.session_id', '=', 'visitor_conversions.session_id')
                        ->whereColumn('cart_event.created_at', '<=', 'visitor_conversions.created_at');
                })
                ->whereIn('cart_event.event_name', $cartNames)
                ->whereBetween('cart_event.created_at', [$range['start'], $range['end']])
                ->selectRaw("COUNT(DISTINCT CONCAT(visitor_conversions.property_id, ':', visitor_conversions.session_id)) as aggregate")
                ->value('aggregate');
        }
        $cartAbandonment = $cartSessions > 0
            ? round(($cartSessions - $completedCartSessions) / $cartSessions * 100, 1) . '%'
            : null;

        $revenueTrend = null;
        if (count($revenueByCurrency) === 1) {
            $currency = $revenueByCurrency[0]['currency'];
            $previousRows = VisitorConversion::query()
                ->when($propertyId !== null, fn ($query) => $query->where('property_id', $propertyId))
                ->whereBetween('created_at', [$range['prev_start'], $range['prev_end']])
                ->where(function ($query) use ($orderGoals) {
                    $query->whereNotNull('order_id')->orWhereIn('goal_name', $orderGoals);
                })
                ->where('currency', $currency)
                ->get(['property_id', 'order_id', 'id', 'revenue', 'created_at'])
                ->sortByDesc('created_at')
                ->unique(fn ($row) => $row->property_id . ':' . ($row->order_id ?: 'conversion-' . $row->id));
            $revenueTrend = $this->trend($revenueByCurrency[0]['amount'], (float) $previousRows->sum('revenue'));
        }

        return [
            'grossRevenue' => count($revenueByCurrency) === 1
                ? $revenueByCurrency[0]['currency'] . ' ' . number_format($revenueByCurrency[0]['amount'], 2)
                : (count($revenueByCurrency) > 1 ? 'Multiple currencies' : null),
            'revenueByCurrency' => $revenueByCurrency,
            'orders' => $distinctOrders->count(),
            'aov' => count($revenueByCurrency) === 1
                ? $revenueByCurrency[0]['currency'] . ' ' . number_format($revenueByCurrency[0]['averageOrderValue'], 2)
                : null,
            'cartAbandonmentRate' => $cartAbandonment,
            'cartSessions' => $cartSessions,
            'completedCartSessions' => $completedCartSessions,
            'revenueTrend' => $revenueTrend,
            'source' => 'client_reported_conversion_events',
        ];
    }

    private function countryFlag(?string $countryCode): string
    {
        $flags = ['SA' => '🇸🇦', 'AE' => '🇦🇪', 'EG' => '🇪🇬', 'KW' => '🇰🇼', 'QA' => '🇶🇦', 'US' => '🇺🇸', 'GB' => '🇬🇧'];
        return $flags[strtoupper((string) $countryCode)] ?? '🌐';
    }
}
