<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('business_profiles', 'auto_order_confirmation_enabled')) {
            Schema::table('business_profiles', function (Blueprint $table): void {
                // Preserve current behavior for existing merchants. New business
                // creation explicitly opts out until the owner verifies setup.
                $table->boolean('auto_order_confirmation_enabled')->default(true);
            });
        }

        if (!Schema::hasColumn('channels', 'auto_order_confirmation_enabled')) {
            Schema::table('channels', function (Blueprint $table): void {
                // NULL inherits the business-level setting.
                $table->boolean('auto_order_confirmation_enabled')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('channels', 'auto_order_confirmation_enabled')) {
            Schema::table('channels', fn (Blueprint $table) => $table->dropColumn('auto_order_confirmation_enabled'));
        }
        if (Schema::hasColumn('business_profiles', 'auto_order_confirmation_enabled')) {
            Schema::table('business_profiles', fn (Blueprint $table) => $table->dropColumn('auto_order_confirmation_enabled'));
        }
    }
};
