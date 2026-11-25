<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('feature_flags')->updateOrInsert(
            ['key' => 'knowledge_dimension_guard'],
            [
                'name' => 'Guard knowledge retrieval by embedding metadata',
                'description' => 'Skips knowledge chunks created with a different embedding provider, model, or vector dimension. Disabled by default until reindexing is complete.',
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
        DB::table('feature_flags')->where('key', 'knowledge_dimension_guard')->delete();
    }
};
