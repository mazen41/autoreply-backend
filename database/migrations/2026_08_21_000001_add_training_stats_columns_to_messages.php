<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Persist per-message AI metadata needed by the training dashboard so the
 * statistics can be computed with real data and efficient aggregate queries.
 *
 *   intent            — the AI-detected intent for this specific reply
 *   detected_language — 'arabic' | 'english' | 'mixed' so we can distinguish a
 *                       NULL/absent dialect (unknown) from an actual English
 *                       message (whose dialect detector returns 'unknown').
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->string('intent', 50)->nullable()->after('detected_dialect');
            $table->string('detected_language', 20)->nullable()->after('intent');

            $table->index('intent');
            $table->index('detected_language');

            // Help the aggregate queries powering the training dashboard.
            $table->index(['is_ai', 'direction', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex(['intent']);
            $table->dropIndex(['detected_language']);
            $table->dropIndex(['is_ai', 'direction', 'created_at']);
            $table->dropIndex(['created_at']);
            $table->dropColumn(['intent', 'detected_language']);
        });
    }
};