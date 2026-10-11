<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_profiles', function (Blueprint $table) {
            $table->timestamp('consent_policy_acknowledged_at')->nullable();
            $table->string('handoff_notification_email')->nullable();
            $table->char('ai_setup_tested_config_hash', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('business_profiles', function (Blueprint $table) {
            $table->dropColumn(['consent_policy_acknowledged_at', 'handoff_notification_email', 'ai_setup_tested_config_hash']);
        });
    }
};
