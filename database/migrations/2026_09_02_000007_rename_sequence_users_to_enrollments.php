<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Only rename if sequence_users exists and sequence_enrollments doesn't exist
        if (Schema::hasTable('sequence_users') && !Schema::hasTable('sequence_enrollments')) {
            Schema::rename('sequence_users', 'sequence_enrollments');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::rename('sequence_enrollments', 'sequence_users');
    }
};
