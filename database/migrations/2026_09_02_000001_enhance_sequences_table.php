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
            $table->string('timezone')->default('UTC')->after('settings');
            $table->json('business_hours')->nullable()->after('timezone');
        });

        // Update trigger_type enum to include order_created
        Schema::table('sequences', function (Blueprint $table) {
            $table->enum('trigger_type', ['manual', 'new_user', 'tag_added', 'no_reply', 'order_created'])
                ->default('manual')
                ->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sequences', function (Blueprint $table) {
            $table->dropColumn(['description', 'channel', 'settings', 'timezone', 'business_hours']);
        });
    }
};
