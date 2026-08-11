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
        Schema::table('messages', function (Blueprint $table) {
            $table->float('confidence_score')->nullable()->after('metadata');
            $table->string('detected_dialect')->nullable()->after('confidence_score');
            $table->index('confidence_score');
            $table->index('detected_dialect');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex(['confidence_score']);
            $table->dropIndex(['detected_dialect']);
            $table->dropColumn(['confidence_score', 'detected_dialect']);
        });
    }
};