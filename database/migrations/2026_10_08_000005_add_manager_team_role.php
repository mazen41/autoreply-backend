<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('team_members', function (Blueprint $table) {
            $table->enum('role', ['owner', 'manager', 'agent', 'viewer'])
                ->default('agent')
                ->change();
        });
    }

    public function down(): void
    {
        // Preserve memberships while restoring the previous supported role set.
        \Illuminate\Support\Facades\DB::table('team_members')
            ->where('role', 'manager')
            ->update(['role' => 'agent']);

        Schema::table('team_members', function (Blueprint $table) {
            $table->enum('role', ['owner', 'agent', 'viewer'])
                ->default('agent')
                ->change();
        });
    }
};
