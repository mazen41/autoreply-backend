<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add no-reply cycle tracking to sequence_enrollments.
 *
 * trigger_message_id  — the inbound Message.id that started this no-reply cycle
 * trigger_ai_reply_id — the outbound AI Message.id that started the timer
 * no_reply_cycle      — monotonically increasing counter per conversation/sequence
 *                       pair; lets us cancel stale pending jobs cheaply
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sequence_enrollments', function (Blueprint $table) {
            if (!Schema::hasColumn('sequence_enrollments', 'trigger_message_id')) {
                $table->unsignedBigInteger('trigger_message_id')->nullable()->after('conversation_id');
            }
            if (!Schema::hasColumn('sequence_enrollments', 'trigger_ai_reply_id')) {
                $table->unsignedBigInteger('trigger_ai_reply_id')->nullable()->after('trigger_message_id');
            }
            if (!Schema::hasColumn('sequence_enrollments', 'no_reply_cycle')) {
                $table->unsignedInteger('no_reply_cycle')->default(0)->after('trigger_ai_reply_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sequence_enrollments', function (Blueprint $table) {
            $table->dropColumn(['trigger_message_id', 'trigger_ai_reply_id', 'no_reply_cycle']);
        });
    }
};
