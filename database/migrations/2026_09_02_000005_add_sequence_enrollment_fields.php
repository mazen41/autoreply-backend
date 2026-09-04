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
        Schema::table('sequence_users', function (Blueprint $table) {
            $table->timestamp('stopped_at')->nullable()->after('completed_at');
            $table->timestamp('next_execution_at')->nullable()->after('stopped_at');
            $table->json('metadata')->nullable()->after('next_execution_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sequence_users', function (Blueprint $table) {
            $table->dropColumn(['stopped_at', 'next_execution_at', 'metadata']);
        });
    }
};
