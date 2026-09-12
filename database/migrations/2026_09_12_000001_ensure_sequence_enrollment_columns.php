<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sequence_enrollments', function (Blueprint $table) {
            if (!Schema::hasColumn('sequence_enrollments', 'stop_reason')) {
                $table->string('stop_reason')->nullable()->after('stopped_at');
            }
            if (!Schema::hasColumn('sequence_enrollments', 'failed_reason')) {
                $table->string('failed_reason')->nullable()->after('stop_reason');
            }
            if (!Schema::hasColumn('sequence_enrollments', 'started_at')) {
                $table->timestamp('started_at')->nullable()->after('id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sequence_enrollments', function (Blueprint $table) {
            $columns = [];
            if (Schema::hasColumn('sequence_enrollments', 'stop_reason')) $columns[] = 'stop_reason';
            if (Schema::hasColumn('sequence_enrollments', 'failed_reason')) $columns[] = 'failed_reason';
            if (Schema::hasColumn('sequence_enrollments', 'started_at')) $columns[] = 'started_at';
            if (!empty($columns)) $table->dropColumn($columns);
        });
    }
};
