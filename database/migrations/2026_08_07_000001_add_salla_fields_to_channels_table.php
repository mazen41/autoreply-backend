<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            // Salla-specific fields
            $table->string('store_id')->nullable()->after('instagram_account_id');
            $table->string('store_name')->nullable()->after('store_id');
            $table->timestamp('token_expires_at')->nullable()->after('connected_at');
            $table->json('metadata')->nullable()->after('token_expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->dropColumn(['store_id', 'store_name', 'token_expires_at', 'metadata']);
        });
    }
};
