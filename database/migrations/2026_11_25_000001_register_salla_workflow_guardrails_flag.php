<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('feature_flags')->updateOrInsert(
            ['key' => 'salla_workflow_guardrails'],
            [
                'name' => 'Salla workflow guardrails',
                'description' => 'Enables broader Arabic human-handoff and order-cancellation phrases, plus grounded shipping-fee answers. Disabled by default for staged rollout.',
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
        DB::table('feature_flags')->where('key', 'salla_workflow_guardrails')->delete();
    }
};
