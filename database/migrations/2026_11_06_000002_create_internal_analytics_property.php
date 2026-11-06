<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('visitor_properties')) {
            return;
        }

        $now = now();
        DB::table('visitor_properties')->insertOrIgnore([
            'property_uid' => 'prop_naz_internal',
            'business_id' => null,
            'name' => 'Nazeer Business (Internal)',
            'domain' => 'nazbiz.io',
            'allowed_domains' => json_encode(['nazbiz.io', 'www.nazbiz.io']),
            'type' => 'internal',
            'platform' => 'nextjs',
            'settings' => json_encode(['anonymize_ip' => true, 'respect_dnt' => true]),
            'is_active' => true,
            'verified_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        // Keep the property row if it has collected any real analytics data.
    }
};
