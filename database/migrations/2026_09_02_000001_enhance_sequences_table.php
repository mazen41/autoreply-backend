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
        Schema::table('sequences', function (Blueprint $table) {
            $table->text('description')->nullable()->after('name');
            $table->enum('channel', ['whatsapp', 'telegram', 'email'])->nullable()->after('trigger_config');
            $table->json('settings')->nullable()->after('is_active');
        });

        // Update trigger_type enum to include order_created
        Schema::table('sequences', function (Blueprint $table) {
            $table->enum('trigger_type', ['manual', 'new_user', 'tag_added', 'no_reply', 'order_created'])
                ->default('manual')
                ->change();
        });

        // Add timezone and business_hours to sequences table
        Schema::table('sequences', function (Blueprint $table) {
            if (!Schema::hasColumn('sequences', 'timezone')) {
                $table->string('timezone')->default('UTC')->nullable();
            }
            if (!Schema::hasColumn('sequences', 'business_hours')) {
                $table->json('business_hours')->nullable();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sequences', function (Blueprint $table) {
            $table->dropColumn(['description', 'channel', 'settings']);
            
            // Remove new columns if they exist
            if (Schema::hasColumn('sequences', 'timezone')) {
                $table->dropColumn('timezone');
            }
            if (Schema::hasColumn('sequences', 'business_hours')) {
                $table->dropColumn('business_hours');
            }
        });
    }
};
