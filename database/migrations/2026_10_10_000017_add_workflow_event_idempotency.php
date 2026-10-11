<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('workflow_executions', 'event_id')) {
            Schema::table('workflow_executions', function (Blueprint $table) {
                $table->string('event_id', 191)->nullable()->after('business_id');
            });
        }

        // Preserve old executions as distinct historical events while keeping
        // new source events protected by their real event identity.
        DB::table('workflow_executions')
            ->whereNull('event_id')
            ->orderBy('id')
            ->chunkById(500, function ($rows): void {
                foreach ($rows as $row) {
                    DB::table('workflow_executions')->where('id', $row->id)
                        ->update(['event_id' => 'legacy-execution:' . $row->id]);
                }
            });

        Schema::table('workflow_executions', function (Blueprint $table) {
            $table->unique(['workflow_id', 'conversation_id', 'event_id'], 'workflow_executions_wf_conv_event_unique');
        });
    }

    public function down(): void
    {
        Schema::table('workflow_executions', function (Blueprint $table) {
            $table->dropUnique('workflow_executions_wf_conv_event_unique');
            $table->dropColumn('event_id');
        });
    }
};
