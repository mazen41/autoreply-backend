<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const FLAGS = [
        'phase3_setup_verification_required' => [
            'name' => 'Require setup verification before enabling AI',
            'description' => 'Requires a passing setup checklist before channel replies can be enabled.',
        ],
        'phase5_workflow_reply_safety' => [
            'name' => 'Pause workflow replies during human takeover',
            'description' => 'Prevents workflow message actions while a human owns the conversation. Explicit AI-off remains enforced independently.',
        ],
        'phase5_sequence_takeover_guard' => [
            'name' => 'Pause sequences during human takeover',
            'description' => 'Prevents sequence enrollment and sends while a human owns the conversation.',
        ],
    ];

    public function up(): void
    {
        foreach (self::FLAGS as $key => $definition) {
            DB::table('feature_flags')->updateOrInsert(
                ['key' => $key],
                [
                    ...$definition,
                    'is_globally_enabled' => false,
                    'requires_business_opt_in' => true,
                    'allowed_business_ids' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        DB::table('feature_flags')
            ->where('key', 'phase3_no_agent_fail_closed')
            ->update([
                'description' => 'Stops automatic replies and opens a handoff task when no eligible active agent is resolved, including an inactive agent pinned to the conversation.',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('feature_flags')->whereIn('key', array_keys(self::FLAGS))->delete();
        DB::table('feature_flags')
            ->where('key', 'phase3_no_agent_fail_closed')
            ->update([
                'description' => 'Stops automatic replies and opens a handoff task when this channel has no eligible agent. Disabled by default for staged rollout.',
                'updated_at' => now(),
            ]);
    }
};
