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
        Schema::table('bot_channels', function (Blueprint $table) {
            $table->boolean('is_primary')->default(false)->after('channel_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bot_channels', function (Blueprint $table) {
            $table->dropColumn('is_primary');
        });
    }
};
