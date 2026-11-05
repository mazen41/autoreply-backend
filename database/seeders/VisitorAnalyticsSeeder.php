<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\VisitorProperty;
use App\Models\VisitorIdentity;
use App\Models\VisitorSession;
use App\Models\VisitorEvent;
use App\Models\VisitorConversion;
use Illuminate\Support\Str;
use Carbon\Carbon;

class VisitorAnalyticsSeeder extends Seeder
{
    public function run(): void
    {
        $properties = [
            [
                'property_uid' => 'prop_naz_internal',
                'name' => 'Nazeer Business (Internal)',
                'domain' => 'nazbiz.io',
                'type' => 'internal',
                'platform' => 'nextjs',
                'allowed_domains' => ['nazbiz.io', 'localhost:3000'],
                'settings' => ['anonymize_ip' => true, 'respect_dnt' => true],
                'is_active' => true,
                'verified_at' => now()->subDays(60),
            ],
            [
                'property_uid' => 'prop_salla_alnahda',
                'name' => 'Al-Nahda Perfumes (Salla)',
                'domain' => 'alnahda-oud.salla.sa',
                'type' => 'customer_store',
                'platform' => 'salla',
                'allowed_domains' => ['alnahda-oud.salla.sa'],
                'settings' => ['anonymize_ip' => true, 'respect_dnt' => true],
                'is_active' => true,
                'verified_at' => now()->subDays(45),
            ],
            [
                'property_uid' => 'prop_shopify_elite',
                'name' => 'Elite Apparel (Shopify)',
                'domain' => 'elite-boutique.myshopify.com',
                'type' => 'customer_store',
                'platform' => 'shopify',
                'allowed_domains' => ['elite-boutique.myshopify.com'],
                'settings' => ['anonymize_ip' => true, 'respect_dnt' => true],
                'is_active' => true,
                'verified_at' => now()->subDays(30),
            ],
            [
                'property_uid' => 'prop_woo_tech',
                'name' => 'TechGate Store (WooCommerce)',
                'domain' => 'shop.techgate.sa',
                'type' => 'customer_store',
                'platform' => 'woocommerce',
                'allowed_domains' => ['shop.techgate.sa'],
                'settings' => ['anonymize_ip' => true, 'respect_dnt' => true],
                'is_active' => true,
                'verified_at' => now()->subDays(20),
            ],
        ];

        foreach ($properties as $propData) {
            $prop = VisitorProperty::updateOrCreate(
                ['property_uid' => $propData['property_uid']],
                $propData
            );

            $this->seedPropertyActivity($prop);
        }
    }

    private function seedPropertyActivity(VisitorProperty $property): void
    {
        $isInternal = $property->type === 'internal';
        $countries = [
            ['country' => 'Saudi Arabia', 'code' => 'SA', 'city' => 'Riyadh', 'weight' => 60],
            ['country' => 'Saudi Arabia', 'code' => 'SA', 'city' => 'Jeddah', 'weight' => 15],
            ['country' => 'UAE', 'code' => 'AE', 'city' => 'Dubai', 'weight' => 12],
            ['country' => 'Egypt', 'code' => 'EG', 'city' => 'Cairo', 'weight' => 6],
            ['country' => 'Kuwait', 'code' => 'KW', 'city' => 'Kuwait City', 'weight' => 4],
            ['country' => 'Qatar', 'code' => 'QA', 'city' => 'Doha', 'weight' => 3],
        ];

        $devices = ['mobile', 'mobile', 'mobile', 'desktop', 'desktop', 'tablet'];
        $browsers = ['Chrome', 'Chrome', 'Safari', 'Safari', 'Edge', 'Firefox'];
        $osList = ['iOS', 'iOS', 'Android', 'Windows', 'macOS'];

        $internalPages = [
            ['path' => '/', 'title' => 'Home - Nazeer Business AI Platform'],
            ['path' => '/pricing', 'title' => 'Pricing & Subscription Plans'],
            ['path' => '/register', 'title' => 'Sign Up - Free Trial Creation'],
            ['path' => '/blog', 'title' => 'Nazeer Tech Blog & Knowledge'],
            ['path' => '/login', 'title' => 'Sign In to Workspace'],
            ['path' => '/tools', 'title' => 'Free Business Tools & ROI Calc'],
        ];

        $storePages = [
            ['path' => '/', 'title' => 'Store Frontpage'],
            ['path' => '/products/royal-oud-oil', 'title' => 'Royal Oud Oil 12ml'],
            ['path' => '/cart', 'title' => 'Shopping Cart'],
            ['path' => '/checkout', 'title' => 'Checkout & Payment Step'],
            ['path' => '/collections/summer-sale', 'title' => 'Summer Collection 2026'],
            ['path' => '/order/confirmed', 'title' => 'Order Confirmation'],
        ];

        $pages = $isInternal ? $internalPages : $storePages;
        $referrers = ['google.com', 'twitter.com', 'linkedin.com', 'instagram.com', 'youtube.com', null];
        $utms = ['saudi_founding_day_2026', 'ai_automation_webinar', 'launch_special_offer', null];

        // Seed sessions over the last 30 days
        for ($day = 30; $day >= 0; $day--) {
            $sessionsCount = rand(25, 45);
            $baseDate = now()->subDays($day);

            for ($s = 0; $s < $sessionsCount; $s++) {
                $sessionDate = $baseDate->copy()->addHours(rand(0, 23))->addMinutes(rand(0, 59));
                $anonId = 'aid_' . Str::random(16);
                $sessionId = 'sid_' . Str::random(16);

                $geo = $countries[array_rand($countries)];
                $device = $devices[array_rand($devices)];
                $browser = $browsers[array_rand($browsers)];
                $os = $osList[array_rand($osList)];
                $refDomain = $referrers[array_rand($referrers)];
                $utm = $utms[array_rand($utms)];
                $entryPage = $pages[0];
                $exitPage = $pages[array_rand($pages)];
                $pageviewsCount = rand(1, 5);
                $duration = rand(20, 480);
                $isBounce = $pageviewsCount <= 1 && $duration < 30;

                $identity = VisitorIdentity::create([
                    'property_id' => $property->id,
                    'anonymous_id' => $anonId,
                    'user_id' => null,
                    'first_seen_at' => $sessionDate,
                    'last_seen_at' => $sessionDate,
                ]);

                $session = VisitorSession::create([
                    'session_id' => $sessionId,
                    'property_id' => $property->id,
                    'visitor_identity_id' => $identity->id,
                    'user_id' => null,
                    'ip_hash' => hash('sha256', '192.168.1.0' . $sessionDate->toDateString()),
                    'country' => $geo['country'],
                    'country_code' => $geo['code'],
                    'city' => $geo['city'],
                    'device_type' => $device,
                    'browser' => $browser,
                    'os' => $os,
                    'referrer' => $refDomain ? 'https://' . $refDomain . '/ref' : null,
                    'referrer_domain' => $refDomain,
                    'entry_url' => 'https://' . $property->domain . $entryPage['path'],
                    'exit_url' => 'https://' . $property->domain . $exitPage['path'],
                    'utm_source' => $utm ? 'campaign' : null,
                    'utm_medium' => $utm ? 'cpc' : null,
                    'utm_campaign' => $utm,
                    'pageviews_count' => $pageviewsCount,
                    'events_count' => $pageviewsCount + rand(1, 4),
                    'duration_seconds' => $duration,
                    'is_bounce' => $isBounce,
                    'started_at' => $sessionDate,
                    'ended_at' => $sessionDate->copy()->addSeconds($duration),
                    'last_activity_at' => $sessionDate->copy()->addSeconds($duration),
                ]);

                // Create pageview events
                for ($p = 0; $p < $pageviewsCount; $p++) {
                    $selectedPage = $pages[$p % count($pages)];
                    VisitorEvent::create([
                        'property_id' => $property->id,
                        'session_id' => $sessionId,
                        'visitor_identity_id' => $identity->id,
                        'user_id' => null,
                        'event_type' => 'pageview',
                        'event_name' => 'pageview',
                        'category' => 'general',
                        'url' => 'https://' . $property->domain . $selectedPage['path'],
                        'path' => $selectedPage['path'],
                        'page_title' => $selectedPage['title'],
                        'created_at' => $sessionDate->copy()->addSeconds($p * 30),
                    ]);
                }

                // Conversions (approx 5% rate)
                if (rand(1, 20) === 1) {
                    $revenue = $isInternal ? rand(99, 399) : rand(120, 650);
                    VisitorConversion::create([
                        'property_id' => $property->id,
                        'session_id' => $sessionId,
                        'visitor_identity_id' => $identity->id,
                        'user_id' => null,
                        'goal_name' => $isInternal ? 'subscription_activated' : 'order_placed',
                        'revenue' => $revenue,
                        'currency' => 'SAR',
                        'order_id' => 'ORD-' . rand(10000, 99999),
                        'created_at' => $sessionDate->copy()->addSeconds($duration),
                    ]);
                }
            }
        }

        // Add 12 live active sessions right now in the last 4 minutes
        for ($i = 0; $i < 12; $i++) {
            $activeDate = now()->subMinutes(rand(0, 4))->subSeconds(rand(5, 50));
            $anonId = 'aid_live_' . Str::random(12);
            $sessionId = 'sid_live_' . Str::random(12);
            $geo = $countries[array_rand($countries)];
            $curPage = $pages[array_rand($pages)];

            $identity = VisitorIdentity::create([
                'property_id' => $property->id,
                'anonymous_id' => $anonId,
                'first_seen_at' => $activeDate,
                'last_seen_at' => now(),
            ]);

            VisitorSession::create([
                'session_id' => $sessionId,
                'property_id' => $property->id,
                'visitor_identity_id' => $identity->id,
                'country' => $geo['country'],
                'country_code' => $geo['code'],
                'city' => $geo['city'],
                'device_type' => $devices[array_rand($devices)],
                'browser' => $browsers[array_rand($browsers)],
                'os' => $osList[array_rand($osList)],
                'referrer_domain' => $referrers[array_rand($referrers)],
                'entry_url' => 'https://' . $property->domain . '/',
                'exit_url' => 'https://' . $property->domain . $curPage['path'],
                'pageviews_count' => rand(1, 4),
                'events_count' => rand(2, 8),
                'duration_seconds' => rand(30, 240),
                'is_bounce' => false,
                'started_at' => $activeDate,
                'last_activity_at' => now(),
            ]);

            VisitorEvent::create([
                'property_id' => $property->id,
                'session_id' => $sessionId,
                'visitor_identity_id' => $identity->id,
                'event_type' => 'custom',
                'event_name' => $isInternal ? 'pricing_plan_select' : 'product_viewed',
                'category' => 'interaction',
                'url' => 'https://' . $property->domain . $curPage['path'],
                'path' => $curPage['path'],
                'page_title' => $curPage['title'],
                'created_at' => now(),
            ]);
        }
    }
}
