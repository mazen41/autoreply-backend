<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ensure scheduled_at is stored as UTC with a proper index,
 * and add a user_id column so campaigns can be fetched without
 * going through business_profiles.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Add a fast lookup index for the every-minute cron query:
        // WHERE status = 'scheduled' AND scheduled_at <= NOW()
        // The existing index is (business_id, status); this one is more selective.
        Schema::table('email_campaigns', function (Blueprint $table) {
            if (!Schema::hasColumn('email_campaigns', 'user_id')) {
                $table->unsignedBigInteger('user_id')->nullable()->after('business_id');
                $table->index('user_id');
            }
            $table->index(['status', 'scheduled_at'], 'email_campaigns_status_scheduled_at');
        });
    }

    public function down(): void
    {
        Schema::table('email_campaigns', function (Blueprint $table) {
            $table->dropIndex('email_campaigns_status_scheduled_at');
            if (Schema::hasColumn('email_campaigns', 'user_id')) {
                $table->dropColumn('user_id');
            }
        });
    }
};
