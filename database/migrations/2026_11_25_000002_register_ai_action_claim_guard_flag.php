<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('feature_flags')->updateOrInsert(
            ['key' => 'ai_action_claim_guard'],
            [
                'name' => 'Block unverified AI action claims',
                'description' => 'Replaces AI claims that completed an order, booking, refund, cancellation, customer-record update, or delivery-record update unless a successful application action confirms the claim. Disabled by default for per-business rollout.',
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
        DB::table('feature_flags')->where('key', 'ai_action_claim_guard')->delete();
    }
};
