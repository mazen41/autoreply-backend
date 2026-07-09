<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->string('gmail_history_id')->nullable()->after('refresh_token');
            $table->timestamp('gmail_watch_expires_at')->nullable()->after('gmail_history_id');
        });
    }

    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->dropColumn(['gmail_history_id', 'gmail_watch_expires_at']);
        });
    }
};
