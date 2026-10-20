<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->foreignId('assigned_to')->nullable()->after('business_profile_id')->constrained('users')->nullOnDelete();
            $table->index(['business_profile_id', 'assigned_to'], 'customers_business_assignee_index');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->dropIndex('customers_business_assignee_index');
            $table->dropConstrainedForeignId('assigned_to');
        });
    }
};
