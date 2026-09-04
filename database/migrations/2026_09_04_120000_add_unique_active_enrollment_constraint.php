<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Enforces "at most one ACTIVE enrollment per (sequence_id, conversation_id)"
     * at the database level, closing the race-condition gap where the
     * application-level check-then-insert in SequenceEnrollmentService could
     * theoretically be beaten by a truly concurrent write.
     *
     * MySQL has no native partial/filtered unique index, so we use a stored
     * generated column that evaluates to conversation_id only when
     * status = 'active', and NULL otherwise. MySQL unique indexes treat NULL
     * as distinct from other NULLs, so any number of non-active rows
     * (completed/stopped/failed) can coexist for the same sequence+conversation,
     * but only one row with status='active' can exist at a time.
     */
    public function up(): void
    {
        if (!Schema::hasColumn('sequence_enrollments', 'conversation_id_if_active')) {
            DB::statement("
                ALTER TABLE sequence_enrollments
                ADD COLUMN conversation_id_if_active BIGINT UNSIGNED
                GENERATED ALWAYS AS (
                    CASE WHEN status = 'active' THEN conversation_id ELSE NULL END
                ) STORED
            ");
        }

        // Before adding the unique index, defensively clean up any pre-existing
        // duplicate active enrollments that may already exist in production data
        // (keep the oldest one, stop the rest with a clear reason so nothing is
        // silently deleted).
        $duplicates = DB::table('sequence_enrollments')
            ->select('sequence_id', 'conversation_id', DB::raw('COUNT(*) as cnt'), DB::raw('MIN(id) as keep_id'))
            ->where('status', 'active')
            ->groupBy('sequence_id', 'conversation_id')
            ->having('cnt', '>', 1)
            ->get();

        foreach ($duplicates as $dup) {
            DB::table('sequence_enrollments')
                ->where('sequence_id', $dup->sequence_id)
                ->where('conversation_id', $dup->conversation_id)
                ->where('status', 'active')
                ->where('id', '!=', $dup->keep_id)
                ->update([
                    'status' => 'stopped',
                    'stopped_at' => now(),
                    'updated_at' => now(),
                ]);
        }

        Schema::table('sequence_enrollments', function (Blueprint $table) {
            $table->unique(
                ['sequence_id', 'conversation_id_if_active'],
                'seq_enroll_unique_active'
            );
        });
    }

    public function down(): void
    {
        Schema::table('sequence_enrollments', function (Blueprint $table) {
            $table->dropUnique('seq_enroll_unique_active');
        });

        if (Schema::hasColumn('sequence_enrollments', 'conversation_id_if_active')) {
            Schema::table('sequence_enrollments', function (Blueprint $table) {
                $table->dropColumn('conversation_id_if_active');
            });
        }
    }
};
