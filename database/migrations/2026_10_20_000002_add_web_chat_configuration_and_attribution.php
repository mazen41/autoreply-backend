<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_profiles', function (Blueprint $table) {
            $table->boolean('web_chat_enabled')->default(false);
            $table->json('web_chat_allowed_domains')->nullable();
        });

        Schema::table('web_chat_sessions', function (Blueprint $table) {
            $table->string('utm_source', 191)->nullable();
            $table->string('utm_medium', 191)->nullable();
            $table->string('utm_campaign', 191)->nullable();
            $table->string('referrer_url', 2048)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('web_chat_sessions', function (Blueprint $table) {
            $table->dropColumn(['utm_source', 'utm_medium', 'utm_campaign', 'referrer_url']);
        });
        Schema::table('business_profiles', function (Blueprint $table) {
            $table->dropColumn(['web_chat_enabled', 'web_chat_allowed_domains']);
        });
    }
};
