<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Remove the unique constraint that prevents re-entry
        // The constraint name is: sequence_users_sequence_id_conversation_id_unique
        // It was created when the table was originally sequence_users
        
        if (Schema::hasTable('sequence_enrollments')) {
            // Get the actual constraint name
            $constraintName = DB::select("
                SELECT CONSTRAINT_NAME 
                FROM information_schema.TABLE_CONSTRAINTS 
                WHERE TABLE_SCHEMA = DATABASE() 
                AND TABLE_NAME = 'sequence_enrollments' 
                AND CONSTRAINT_TYPE = 'UNIQUE'
                AND CONSTRAINT_NAME LIKE '%sequence_id_conversation_id%'
            ");
            
            if ($constraintName) {
                $name = $constraintName[0]->CONSTRAINT_NAME;
                Schema::table('sequence_enrollments', function (Blueprint $table) use ($name) {
                    $table->dropUnique($name);
                });
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('sequence_enrollments')) {
            Schema::table('sequence_enrollments', function (Blueprint $table) {
                // Restore the unique constraint
                $table->unique(['sequence_id', 'conversation_id']);
            });
        }
    }
};
