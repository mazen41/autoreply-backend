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
        Schema::table('sequence_steps', function (Blueprint $table) {
            $table->enum('step_type', ['message', 'delay', 'condition', 'action'])->default('message')->after('step_order');
            $table->json('config')->nullable()->after('message');
            $table->string('delay_unit')->nullable()->after('delay_hours');
            $table->json('condition_config')->nullable()->after('delay_unit');
            $table->boolean('is_active')->default(true)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sequence_steps', function (Blueprint $table) {
            $table->dropColumn(['step_type', 'config', 'delay_unit', 'condition_config']);
        });
    }
};
