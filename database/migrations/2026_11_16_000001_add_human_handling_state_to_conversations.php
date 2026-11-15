<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->timestamp('human_handling_started_at')->nullable()->after('escalation_reason');
            $table->unsignedInteger('human_handled_count')->default(0)->after('human_handling_started_at');
        });

        // Preserve active handoffs where an agent already replied before this
        // state was introduced, so the new Handled action is available to them.
        DB::table('conversations')
            ->where('requires_human', true)
            ->whereNotNull('escalated_at')
            ->whereExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('messages')
                    ->whereColumn('messages.conversation_id', 'conversations.id')
                    ->where('messages.direction', 'outbound')
                    ->where('messages.status', 'manual')
                    ->where('messages.is_ai', false)
                    ->whereColumn('messages.created_at', '>=', 'conversations.escalated_at');
            })
            ->update(['human_handling_started_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn(['human_handling_started_at', 'human_handled_count']);
        });
    }
};
