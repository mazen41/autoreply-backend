<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            // Raw HTML body for channels that provide it (Gmail).
            // `content` remains the plain-text version, used for AI context,
            // previews, and non-HTML channels.
            $table->longText('content_html')->nullable()->after('content');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn('content_html');
        });
    }
};
