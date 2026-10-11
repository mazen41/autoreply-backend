<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('feature_flags', function (Blueprint $table): void {
            $table->boolean('requires_business_opt_in')->default(false)->after('is_globally_enabled');
        });

        Schema::create('business_feature_flag_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained('business_profiles')->cascadeOnDelete();
            $table->foreignId('feature_flag_id')->constrained('feature_flags')->cascadeOnDelete();
            $table->boolean('enabled')->default(false);
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('enabled_at')->nullable();
            $table->timestamps();
            $table->unique(['business_id', 'feature_flag_id'], 'business_feature_flag_unique');
            $table->index(['business_id', 'enabled']);
        });

        DB::table('feature_flags')->updateOrInsert(
            ['key' => 'phase3_no_agent_fail_closed'],
            [
                'name' => 'Pause replies when no agent is active',
                'description' => 'Stops automatic replies and opens a handoff task when this channel has no eligible agent. Disabled by default for staged rollout.',
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
        DB::table('feature_flags')->where('key', 'phase3_no_agent_fail_closed')->delete();
        Schema::dropIfExists('business_feature_flag_assignments');
        Schema::table('feature_flags', function (Blueprint $table): void {
            $table->dropColumn('requires_business_opt_in');
        });
    }
};
