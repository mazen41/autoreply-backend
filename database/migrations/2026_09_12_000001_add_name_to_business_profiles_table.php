<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_profiles', function (Blueprint $table) {
            if (!Schema::hasColumn('business_profiles', 'name')) {
                $table->string('name')->nullable()->after('user_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('business_profiles', function (Blueprint $table) {
            $table->dropColumn('name');
        });
    }
};
