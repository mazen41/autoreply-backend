<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('feature_flags')->updateOrInsert(
            ['key' => 'knowledge_relevance_floor'],
            [
                'name' => 'Use stricter source-grounded knowledge replies',
                'description' => 'Uses conservative source-only answer instructions for non-commerce conversations. Relevance filtering remains gated on a separately approved threshold.',
                'is_globally_enabled' => false,
                'requires_business_opt_in' => true,
                'allowed_business_ids' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        DB::table('feature_flags')->where('key', 'knowledge_relevance_floor')->delete();
    }
};
