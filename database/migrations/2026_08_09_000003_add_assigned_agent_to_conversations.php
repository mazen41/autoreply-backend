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
        Schema::table('conversations', function (Blueprint $table) {
            $table->foreignId('assigned_agent_id')->nullable()->after('business_id');
            $table->timestamp('assigned_at')->nullable()->after('assigned_agent_id');
            $table->index('assigned_agent_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropForeign(['assigned_agent_id']);
            $table->dropIndex(['assigned_agent_id']);
            $table->dropColumn(['assigned_agent_id', 'assigned_at']);
        });
    }
};