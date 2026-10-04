<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bot scoping for automation workflows.
     *
     * Backward-compatible semantics:
     *   - bot_id NULL  (all existing rows): business-global workflow — runs
     *     for every conversation of the business, bot-resolved or not.
     *   - bot_id set:  bot-specific workflow — runs only for conversations
     *     whose resolved bot matches.
     */
    public function up(): void
    {
        Schema::table('automation_workflows', function (Blueprint $table) {
            $table->foreignId('bot_id')
                ->nullable()
                ->after('business_id')
                ->constrained('bots')
                ->nullOnDelete();
            $table->index(['business_id', 'bot_id']);
        });
    }

    public function down(): void
    {
        Schema::table('automation_workflows', function (Blueprint $table) {
            $table->dropForeign(['bot_id']);
            $table->dropIndex(['business_id', 'bot_id']);
            $table->dropColumn('bot_id');
        });
    }
};
