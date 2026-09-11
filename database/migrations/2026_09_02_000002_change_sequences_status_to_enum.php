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
        Schema::table('sequences', function (Blueprint $table) {
            // Drop the index that references is_active first
            $table->dropIndex(['business_id', 'is_active']);
            
            // Now add the new status column
            $table->enum('status', ['draft', 'active', 'paused', 'archived'])->default('draft')->after('is_active');
            
            // Then drop the is_active column
            $table->dropColumn('is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sequences', function (Blueprint $table) {
            // Drop the status column first
            $table->dropColumn('status');
            
            // Then add back is_active
            $table->boolean('is_active')->default(true)->after('trigger_config');
            
            // Recreate the index
            $table->index(['business_id', 'is_active']);
        });
    }
};
