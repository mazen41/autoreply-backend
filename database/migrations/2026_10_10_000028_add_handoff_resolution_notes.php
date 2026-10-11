<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('handoff_tasks', function (Blueprint $table) {
            $table->text('resolution_note')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('handoff_tasks', function (Blueprint $table) {
            $table->dropColumn('resolution_note');
        });
    }
};
