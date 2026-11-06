<?php

namespace Database\Seeders;

use App\Models\VisitorProperty;
use Illuminate\Database\Seeder;

/**
 * Creates the first-party analytics property only. Traffic, users, and
 * conversions must come from real tracking; this seeder never fabricates data.
 */
class VisitorAnalyticsSeeder extends Seeder
{
    public function run(): void
    {
        VisitorProperty::firstOrCreate(
            ['property_uid' => 'prop_naz_internal'],
            [
                'name' => 'Nazeer Business (Internal)',
                'domain' => 'nazbiz.io',
                'allowed_domains' => ['nazbiz.io', 'www.nazbiz.io'],
                'type' => 'internal',
                'platform' => 'nextjs',
                'settings' => ['anonymize_ip' => true, 'respect_dnt' => true],
                'is_active' => true,
                'verified_at' => null,
            ]
        );
    }
}
