<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('feature_flags')->updateOrInsert(
            ['key' => 'knowledge_embedding_failsafe'],
            [
                'name' => 'Continue replies when knowledge embeddings fail',
                'description' => 'Continues customer replies without retrieved knowledge when query embedding is unavailable. Disabled by default for staged rollout.',
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
        DB::table('feature_flags')->where('key', 'knowledge_embedding_failsafe')->delete();
    }
};
