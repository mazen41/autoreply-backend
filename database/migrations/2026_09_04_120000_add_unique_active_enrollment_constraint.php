<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Enforces "at most one ACTIVE enrollment per (sequence_id, conversation_id)"
     * at the database level using a stored generated column + unique index.
     *
     * Rewritten to be fully idempotent and to avoid the FK-drop/re-add
     * dance that failed in production (sequence_enrollments has no FK on
     * conversation_id — only a plain index — so we never need to touch FKs).
     */
    public function up(): void
    {
        // Step 1 — add the generated column if it doesn't exist yet.
        // The column already exists in production (added by a previous failed
        // migration attempt), so the hasColumn guard makes this safe to re-run.
        if (!Schema::hasColumn('sequence_enrollments', 'conversation_id_if_active')) {
            DB::statement("
                ALTER TABLE sequence_enrollments
                ADD COLUMN conversation_id_if_active BIGINT UNSIGNED
                GENERATED ALWAYS AS (
                    CASE WHEN status = 'active' THEN conversation_id ELSE NULL END
                ) STORED
            ");
        }

        // Step 2 — clean up any duplicate active enrollments before adding
        // the unique index (idempotent: no duplicates → no rows updated).
        $duplicates = DB::table('sequence_enrollments')
            ->select(
                'sequence_id',
                'conversation_id',
                DB::raw('COUNT(*) as cnt'),
                DB::raw('MIN(id) as keep_id')
            )
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
                    'status'     => 'stopped',
                    'stopped_at' => now(),
                    'updated_at' => now(),
                ]);
        }

        // Step 3 — add the unique index only if it doesn't exist yet.
        $indexExists = DB::select("
            SELECT INDEX_NAME
            FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME   = 'sequence_enrollments'
              AND INDEX_NAME   = 'seq_enroll_unique_active'
            LIMIT 1
        ");

        if (empty($indexExists)) {
            DB::statement("
                ALTER TABLE sequence_enrollments
                ADD UNIQUE KEY seq_enroll_unique_active (sequence_id, conversation_id_if_active)
            ");
        }
    }

    public function down(): void
    {
        $indexExists = DB::select("
            SELECT INDEX_NAME
            FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME   = 'sequence_enrollments'
              AND INDEX_NAME   = 'seq_enroll_unique_active'
            LIMIT 1
        ");

        if (!empty($indexExists)) {
            DB::statement("ALTER TABLE sequence_enrollments DROP INDEX seq_enroll_unique_active");
        }

        if (Schema::hasColumn('sequence_enrollments', 'conversation_id_if_active')) {
            Schema::table('sequence_enrollments', function (Blueprint $table) {
                $table->dropColumn('conversation_id_if_active');
            });
        }
    }
};
