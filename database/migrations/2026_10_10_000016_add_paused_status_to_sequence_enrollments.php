<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('sequence_enrollments')) {
            return;
        }

        Schema::table('sequence_enrollments', function (Blueprint $table) {
            $table->enum('status', ['active', 'paused', 'completed', 'stopped', 'failed'])->default('active')->change();
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('sequence_enrollments')) {
            return;
        }

        DB::table('sequence_enrollments')->where('status', 'paused')->update(['status' => 'stopped']);
        Schema::table('sequence_enrollments', function (Blueprint $table) {
            $table->enum('status', ['active', 'completed', 'stopped', 'failed'])->default('active')->change();
        });
    }
};
