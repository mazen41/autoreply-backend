<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sequence_steps', function (Blueprint $table) {
            if (!Schema::hasColumn('sequence_steps', 'delay_minutes')) {
                $table->integer('delay_minutes')->default(0)->after('delay_hours');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sequence_steps', function (Blueprint $table) {
            if (Schema::hasColumn('sequence_steps', 'delay_minutes')) {
                $table->dropColumn('delay_minutes');
            }
        });
    }
};
