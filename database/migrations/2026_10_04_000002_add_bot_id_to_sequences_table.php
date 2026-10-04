<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bot scoping for sequences.
     *
     * Backward-compatible semantics (identical to automation_workflows):
     *   - bot_id NULL  (all existing rows): business-global sequence —
     *     enrolls any conversation of the business.
     *   - bot_id set:  bot-specific sequence — enrolls only conversations
     *     whose resolved bot matches.
     */
    public function up(): void
    {
        // The Phase 1 schema migration may already have added bot_id for
        // fresh installs — guard so both paths converge to the same state.
        if (!Schema::hasColumn('sequences', 'bot_id')) {
            Schema::table('sequences', function (Blueprint $table) {
                $table->foreignId('bot_id')
                    ->nullable()
                    ->after('business_id')
                    ->constrained('bots')
                    ->nullOnDelete();
            });
        }

        if (!Schema::hasIndex('sequences', 'sequences_business_id_bot_id_index')) {
            Schema::table('sequences', function (Blueprint $table) {
                $table->index(['business_id', 'bot_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::table('sequences', function (Blueprint $table) {
            $table->dropForeign(['bot_id']);
            $table->dropIndex(['business_id', 'bot_id']);
            $table->dropColumn('bot_id');
        });
    }
};
