<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            // Check if columns don't exist before adding them
            if (!Schema::hasColumn('subscriptions', 'billing_cycle')) {
                $table->enum('billing_cycle', ['monthly', 'yearly'])->default('monthly')->after('package_id');
            }
            if (!Schema::hasColumn('subscriptions', 'grace_period_ends_at')) {
                $table->timestamp('grace_period_ends_at')->nullable()->after('ends_at');
            }
            if (!Schema::hasColumn('subscriptions', 'usage_count')) {
                $table->integer('usage_count')->default(0)->after('package_id');
            }
            if (!Schema::hasColumn('subscriptions', 'usage_limit')) {
                $table->integer('usage_limit')->default(0)->after('usage_count');
            }
            if (!Schema::hasColumn('subscriptions', 'last_usage_alert_at')) {
                $table->timestamp('last_usage_alert_at')->nullable()->after('grace_period_ends_at');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn(['billing_cycle', 'grace_period_ends_at', 'usage_count', 'usage_limit', 'last_usage_alert_at']);
        });
    }
};