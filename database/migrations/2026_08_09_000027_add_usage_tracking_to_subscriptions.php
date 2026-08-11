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
            $table->timestamp('grace_period_ends_at')->nullable()->after('ends_at');
            $table->integer('usage_count')->default(0)->after('package_id');
            $table->integer('usage_limit')->default(0)->after('usage_count');
            $table->timestamp('last_usage_alert_at')->nullable()->after('grace_period_ends_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn(['grace_period_ends_at', 'usage_count', 'usage_limit', 'last_usage_alert_at']);
        });
    }
};