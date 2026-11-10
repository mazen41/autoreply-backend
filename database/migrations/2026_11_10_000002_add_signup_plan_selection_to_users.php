<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('signup_plan_choice', 16)->nullable()->after('business_id');
            $table->foreignId('signup_package_id')->nullable()->after('signup_plan_choice')->constrained('packages')->nullOnDelete();
            $table->string('signup_billing_cycle', 16)->nullable()->after('signup_package_id');
        });

        DB::table('packages')->where('name', 'Free')->update(['is_active' => false]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropForeign(['signup_package_id']);
            $table->dropColumn(['signup_plan_choice', 'signup_package_id', 'signup_billing_cycle']);
        });

        DB::table('packages')->where('name', 'Free')->update(['is_active' => true]);
    }
};
