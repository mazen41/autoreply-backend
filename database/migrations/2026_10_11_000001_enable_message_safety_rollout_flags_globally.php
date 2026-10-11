<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const FLAGS = [
        'phase3_no_agent_fail_closed',
        'phase3_setup_verification_required',
        'phase5_workflow_reply_safety',
        'phase5_sequence_takeover_guard',
    ];

    public function up(): void
    {
        DB::table('feature_flags')
            ->whereIn('key', self::FLAGS)
            ->update([
                'is_globally_enabled' => true,
                'requires_business_opt_in' => false,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('feature_flags')
            ->whereIn('key', self::FLAGS)
            ->update([
                'is_globally_enabled' => false,
                'requires_business_opt_in' => true,
                'updated_at' => now(),
            ]);
    }
};
