<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('sequence_step_executions', 'execution_index')) {
            Schema::table('sequence_step_executions', function (Blueprint $table) {
                $table->unsignedInteger('execution_index')->nullable()->after('sequence_enrollment_id');
            });
        }

        $indexes = [];
        DB::table('sequence_step_executions')
            ->orderBy('sequence_enrollment_id')
            ->orderBy('id')
            ->chunkById(500, function ($rows) use (&$indexes) {
                foreach ($rows as $row) {
                    if ($row->execution_index !== null) {
                        $indexes[$row->sequence_enrollment_id ?? 0] = max(
                            $indexes[$row->sequence_enrollment_id ?? 0] ?? 0,
                            (int) $row->execution_index,
                        );
                        continue;
                    }

                    $scope = $row->sequence_enrollment_id ?? 0;
                    $indexes[$scope] = ($indexes[$scope] ?? 0) + 1;
                    DB::table('sequence_step_executions')
                        ->where('id', $row->id)
                        ->update(['execution_index' => $indexes[$scope]]);
                }
            });

        Schema::table('sequence_step_executions', function (Blueprint $table) {
            $table->unique(
                ['sequence_enrollment_id', 'execution_index'],
                'seq_enrollment_execution_index_unique'
            );
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('sequence_step_executions', 'execution_index')) {
            Schema::table('sequence_step_executions', function (Blueprint $table) {
                $table->dropUnique('seq_enrollment_execution_index_unique');
                $table->dropColumn('execution_index');
            });
        }
    }
};
