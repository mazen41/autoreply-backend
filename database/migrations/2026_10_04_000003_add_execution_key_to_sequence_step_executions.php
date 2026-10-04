<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Add unique execution key to sequence_step_executions for idempotent dispatch.
 *
 * The execution key is a deterministic hash of (enrollment_id, step_id, scheduled_at).
 * A unique index on this key ensures that even if the same step is dispatched twice
 * (e.g. scheduler re-run, duplicate webhook), only one execution record is created.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Add execution_key column if it doesn't exist
        if (!Schema::hasColumn('sequence_step_executions', 'execution_key')) {
            Schema::table('sequence_step_executions', function (Blueprint $table) {
                $table->string('execution_key', 64)->nullable()->after('sequence_step_id');
                $table->index('execution_key');
            });
        }

        // Backfill execution_key for existing rows
        $executions = DB::table('sequence_step_executions')
            ->whereNull('execution_key')
            ->get();

        foreach ($executions as $exec) {
            $key = hash('sha256', implode('|', [
                $exec->sequence_enrollment_id,
                $exec->sequence_step_id,
                $exec->scheduled_at ? $exec->scheduled_at->toDateTimeString() : '0',
            ]));

            DB::table('sequence_step_executions')
                ->where('id', $exec->id)
                ->update(['execution_key' => $key]);
        }

        // Make execution_key NOT NULL and add unique index
        Schema::table('sequence_step_executions', function (Blueprint $table) {
            $table->string('execution_key', 64)->nullable(false)->change();
        });

        // Drop the old index if it exists, then add unique
        try {
            Schema::table('sequence_step_executions', function (Blueprint $table) {
                $table->dropIndex('execution_key');
            });
        } catch (\Exception $e) {
            // Index may not exist
        }

        Schema::table('sequence_step_executions', function (Blueprint $table) {
            $table->unique('execution_key', 'seq_exec_unique_key');
        });
    }

    public function down(): void
    {
        Schema::table('sequence_step_executions', function (Blueprint $table) {
            try {
                $table->dropUnique('seq_exec_unique_key');
            } catch (\Exception $e) {
                // Index may not exist
            }
            $table->dropColumn('execution_key');
        });
    }
};
